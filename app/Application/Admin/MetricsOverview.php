<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Enums\BillingRunStatus;
use App\Models\BillingRun;
use App\Models\Payment;
use Illuminate\Support\Carbon;

/**
 * Kennzahlen des Adminbereichs (Masterprompt 19, 20).
 *
 * VERBINDLICH: datensparsam und ohne externes Tracking. Alle Zahlen entstehen
 * aus den ohnehin vorhandenen Fachdaten. Es gibt keinen Analysetracker, kein
 * Zaehlpixel, kein Session-Recording und keine Uebermittlung an Dritte.
 * Personenbezogene Einzelverlaeufe werden nicht ausgewertet, sondern nur
 * Summen und Verteilungen.
 */
final class MetricsOverview
{
    /**
     * Status, ab dem eine Vorschau vorlag. Grundlage der Conversion.
     *
     * @var list<string>
     */
    private const array MIT_VORSCHAU = [
        'PREVIEW_READY',
        'CHECKOUT_PENDING',
        'PAID',
        'FINALIZING',
        'FINALIZED',
    ];

    /**
     * @var list<string>
     */
    private const array BEZAHLT = [
        'PAID',
        'FINALIZING',
        'FINALIZED',
    ];

    public function __construct(private readonly PaymentOverview $payments) {}

    /**
     * Umsatz eines Zeitraums.
     *
     * @return array{umsatz_cent: int, erstattet_cent: int, zahlungen: int}
     */
    public function revenue(Carbon $from, Carbon $to): array
    {
        return [
            'umsatz_cent' => $this->payments->revenueCent($from, $to),
            'erstattet_cent' => $this->payments->refundedCent($from, $to),
            'zahlungen' => Payment::query()
                ->whereNotNull('paid_at')
                ->whereBetween('paid_at', [$from, $to])
                ->count(),
        ];
    }

    /**
     * Umsatz der letzten Monate, aeltester Monat zuerst.
     *
     * @return array<string, int> Monat MM.JJJJ auf Umsatz in Cent
     */
    public function monthlyRevenueCent(int $months = 6): array
    {
        $series = [];
        $start = Carbon::now()->startOfMonth();

        for ($offset = $months - 1; $offset >= 0; $offset--) {
            $month = $start->copy()->subMonths($offset);

            $series[$month->format('m.Y')] = $this->payments->revenueCent(
                $month,
                $month->copy()->endOfMonth(),
            );
        }

        return $series;
    }

    /**
     * Anzahl der Abrechnungslaeufe je Status.
     *
     * @return array<string, int>
     */
    public function runsPerStatus(): array
    {
        $counts = [];

        foreach (BillingRunStatus::cases() as $status) {
            $counts[$status->value] = BillingRun::query()
                ->where('status', $status->value)
                ->count();
        }

        return $counts;
    }

    /**
     * Conversion von Vorschau zu Zahlung.
     *
     * @return array{mit_vorschau: int, bezahlt: int, quote_prozent: float|null}
     */
    public function previewToPaymentConversion(): array
    {
        $withPreview = BillingRun::query()->whereIn('status', self::MIT_VORSCHAU)->count();
        $paid = BillingRun::query()->whereIn('status', self::BEZAHLT)->count();

        return [
            'mit_vorschau' => $withPreview,
            'bezahlt' => $paid,
            'quote_prozent' => $withPreview === 0
                ? null
                : round($paid / $withPreview * 100, 1),
        ];
    }

    /**
     * Abbruchschritte: in welchem Schritt des gefuehrten Ablaufs stehen die
     * nicht abgeschlossenen Laeufe.
     *
     * @return array<int, int> Schritt auf Anzahl
     */
    public function abandonmentSteps(): array
    {
        /** @var array<int|string, mixed> $raw */
        $raw = BillingRun::query()
            ->whereNotIn('status', [
                BillingRunStatus::FINALIZED->value,
                BillingRunStatus::CANCELLED->value,
            ])
            ->selectRaw('wizard_step, count(*) as anzahl')
            ->groupBy('wizard_step')
            ->orderBy('wizard_step')
            ->pluck('anzahl', 'wizard_step')
            ->all();

        $steps = [];

        foreach ($raw as $step => $count) {
            $steps[(int) $step] = is_numeric($count) ? (int) $count : 0;
        }

        return $steps;
    }

    public static function formatCent(int $cent): string
    {
        return number_format($cent / 100, 2, ',', '.').' EUR';
    }
}
