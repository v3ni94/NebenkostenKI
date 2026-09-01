<?php

declare(strict_types=1);

namespace App\Application\Wizard;

use App\Application\Account\AuditRecorder;
use App\Application\Wizard\Dto\PrepaymentRow;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;
use App\Enums\PrepaymentKind;
use App\Enums\ValueSource;
use App\Models\BillingRun;
use App\Models\Prepayment;
use App\Models\Tenancy;
use App\Models\Unit;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Schritt 7 des geführten Ablaufs: Vorauszahlungen.
 *
 * Je Mietverhältnis werden geführt:
 *   - die vertragliche monatliche Betriebskostenvorauszahlung
 *   - optional getrennt die Heizkostenvorauszahlung
 *   - die daraus berechnete Sollsumme für den Nutzungszeitraum
 *   - die tatsächlich geleisteten Vorauszahlungen als editierbares Feld
 *   - die Herkunft der Angabe
 *
 * VERBINDLICH (Masterprompt 11.4): Abgezogen wird ausschließlich der Ist-Wert.
 * Die Annahme Ist gleich Soll darf vorgeschlagen werden, muss aber ausdrücklich
 * bestätigt werden und wird mit Nutzer und Zeitpunkt protokolliert. Es wird
 * nichts geschätzt.
 *
 * Berechnung der Sollsumme: Der Monatsbetrag wird taggenau auf den
 * Nutzungszeitraum umgelegt. Rechenweg:
 * Monatsbetrag × 12 × Nutzungstage ÷ Tage des Abrechnungszeitraums, gerundet
 * auf Cent. Gerechnet wird mit brick/math, niemals mit float.
 */
final class PrepaymentWorkspace
{
    public const string AUDIT_ACTION = 'billing_run.prepayments_saved';

    public const string AUDIT_ASSUMPTION = 'billing_run.prepayment_assumption_confirmed';

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @return list<PrepaymentRow>
     */
    public function rows(BillingRun $billingRun): array
    {
        $billingRun->loadMissing(['property.units.tenancies', 'prepayments']);

        $period = new DatePeriodRange($billingRun->period_start, $billingRun->period_end);

        /** @var array<string, list<Prepayment>> $stored */
        $stored = [];

        foreach ($billingRun->prepayments as $prepayment) {
            $stored[$prepayment->tenancy_id][] = $prepayment;
        }

        $rows = [];

        foreach ($this->units($billingRun) as $unit) {
            foreach ($this->tenancies($unit) as $tenancy) {
                $usage = $this->usagePeriod($tenancy, $period);

                if (! $usage instanceof DatePeriodRange) {
                    continue;
                }

                $rows[] = $this->row($tenancy, $unit, $usage, $period, $stored[(string) $tenancy->getKey()] ?? []);
            }
        }

        return $rows;
    }

    /**
     * @param  list<Prepayment>  $stored
     */
    private function row(
        Tenancy $tenancy,
        Unit $unit,
        DatePeriodRange $usage,
        DatePeriodRange $period,
        array $stored,
    ): PrepaymentRow {
        $monthlyOperating = $tenancy->monthly_operating_prepayment_cent === null
            ? null
            : Money::fromCents($tenancy->monthly_operating_prepayment_cent);
        $monthlyHeating = $tenancy->monthly_heating_prepayment_cent === null
            ? null
            : Money::fromCents($tenancy->monthly_heating_prepayment_cent);

        $target = $this->target($monthlyOperating, $monthlyHeating, $tenancy->heating_prepayment_separate, $usage, $period);

        $actual = null;
        $assumed = false;
        $confirmed = false;
        $source = $tenancy->contract_data_source ?? ValueSource::MIETVERTRAG;
        $hasActual = false;
        $storedTarget = 0;

        foreach ($stored as $prepayment) {
            $storedTarget += $prepayment->target_cent;

            if ($prepayment->assumed_equal_to_target) {
                $assumed = true;
            }

            if ($prepayment->actual_cent !== null) {
                $hasActual = true;
                $actual = Money::fromCents(($actual->cents ?? 0) + $prepayment->actual_cent);
            }

            if ($prepayment->confirmed_at instanceof Carbon) {
                $confirmed = true;
            }

            $source = $prepayment->source;
        }

        if ($stored !== []) {
            $target = Money::fromCents($storedTarget);
        }

        if ($assumed && ! $hasActual) {
            $actual = $target;
        }

        return new PrepaymentRow(
            (string) $tenancy->getKey(),
            $tenancy->tenant_display_name,
            $unit->label,
            $usage,
            $monthlyOperating,
            $monthlyHeating,
            $tenancy->heating_prepayment_separate,
            $target,
            $actual,
            $assumed,
            $source->label(),
            $confirmed,
            $this->explanation($monthlyOperating, $monthlyHeating, $tenancy->heating_prepayment_separate, $usage, $period, $target),
        );
    }

    /**
     * Speichert die erfassten Ist-Werte und die bestätigte Annahme.
     *
     * @param  array<string, array{ist_cent?: int|null, annahme?: bool, herkunft?: string}>  $eingaben
     */
    public function save(BillingRun $billingRun, User $actor, array $eingaben): int
    {
        $rows = $this->rows($billingRun);
        $gespeichert = 0;
        $annahmen = [];

        DB::transaction(function () use ($billingRun, $actor, $eingaben, $rows, &$gespeichert, &$annahmen): void {
            foreach ($rows as $row) {
                $eingabe = $eingaben[$row->tenancyId] ?? null;

                if ($eingabe === null) {
                    continue;
                }

                $annahme = (bool) ($eingabe['annahme'] ?? false);
                $istCent = $eingabe['ist_cent'] ?? null;

                if (! $annahme && ! is_int($istCent)) {
                    continue;
                }

                $herkunft = $this->source($eingabe['herkunft'] ?? null, $annahme);

                Prepayment::query()
                    ->where('billing_run_id', $billingRun->getKey())
                    ->where('tenancy_id', $row->tenancyId)
                    ->delete();

                Prepayment::query()->create([
                    'organization_id' => $billingRun->getAttribute('organization_id'),
                    'billing_run_id' => $billingRun->getKey(),
                    'tenancy_id' => $row->tenancyId,
                    'kind' => PrepaymentKind::BETRIEBSKOSTEN,
                    'period_start' => $row->usagePeriod->startIso(),
                    'period_end' => $row->usagePeriod->endIso(),
                    'target_cent' => $row->targetTotal->cents,
                    'actual_cent' => $annahme ? $row->targetTotal->cents : $istCent,
                    'source' => $herkunft,
                    'assumed_equal_to_target' => $annahme,
                    'confirmed_by_user_id' => $actor->getKey(),
                    'confirmed_at' => Carbon::now(),
                    'note' => $annahme
                        ? 'Die Übernahme der Sollwerte als Ist-Werte wurde ausdrücklich bestätigt.'
                        : null,
                ]);

                $gespeichert++;

                if ($annahme) {
                    $annahmen[] = $row->tenantLabel;
                }
            }
        });

        $organizationId = $billingRun->getAttribute('organization_id');

        $this->audit->record(
            action: self::AUDIT_ACTION,
            subject: $billingRun,
            actor: $actor,
            organization: is_string($organizationId) ? $organizationId : null,
            metadata: ['zeilen' => $gespeichert],
        );

        foreach ($annahmen as $label) {
            $this->audit->record(
                action: self::AUDIT_ASSUMPTION,
                subject: $billingRun,
                actor: $actor,
                organization: is_string($organizationId) ? $organizationId : null,
                metadata: ['mietverhaeltnis' => $label],
                reason: 'Der Nutzer hat die Annahme Ist gleich Soll ausdrücklich bestätigt.',
            );
        }

        return $gespeichert;
    }

    /**
     * Offene Zeilen. Ohne diesen Schritt ist keine Abrechnung möglich.
     *
     * @return list<string>
     */
    public function openReasons(BillingRun $billingRun): array
    {
        $reasons = [];

        foreach ($this->rows($billingRun) as $row) {
            if ($row->isOpen()) {
                $reasons[] = sprintf(
                    'Für %s fehlen die tatsächlich geleisteten Vorauszahlungen. Bitte tragen Sie den Betrag ein '
                    .'oder bestätigen Sie die Annahme Ist gleich Soll.',
                    $row->tenantLabel
                );
            }
        }

        return $reasons;
    }

    public function isComplete(BillingRun $billingRun): bool
    {
        return $this->openReasons($billingRun) === [];
    }

    /**
     * Sollsumme des Nutzungszeitraums, taggenau und ohne float.
     */
    public function target(
        ?Money $monthlyOperating,
        ?Money $monthlyHeating,
        bool $heatingSeparate,
        DatePeriodRange $usage,
        DatePeriodRange $period,
    ): Money {
        $monthlyCents = $monthlyOperating->cents ?? 0;

        if ($heatingSeparate) {
            $monthlyCents += $monthlyHeating->cents ?? 0;
        }

        if ($monthlyCents === 0 || $period->days() === 0) {
            return Money::zero();
        }

        $value = BigDecimal::of($monthlyCents)
            ->multipliedBy(12)
            ->multipliedBy($usage->days())
            ->dividedBy($period->days(), 0, RoundingMode::HALF_UP);

        return Money::fromCents($value->toInt());
    }

    private function explanation(
        ?Money $monthlyOperating,
        ?Money $monthlyHeating,
        bool $heatingSeparate,
        DatePeriodRange $usage,
        DatePeriodRange $period,
        Money $target,
    ): string {
        if (! $monthlyOperating instanceof Money && ! $monthlyHeating instanceof Money) {
            return 'Es ist kein monatlicher Vorauszahlungsbetrag hinterlegt. Bitte tragen Sie die geleisteten '
                .'Vorauszahlungen ein.';
        }

        $monatlich = Money::fromCents(
            ($monthlyOperating->cents ?? 0) + ($heatingSeparate ? ($monthlyHeating->cents ?? 0) : 0)
        );

        return sprintf(
            '%s monatlich × 12 × %d Nutzungstage ÷ %d Tage des Abrechnungszeitraums ergeben %s.',
            $monatlich->format(),
            $usage->days(),
            $period->days(),
            $target->format()
        );
    }

    private function source(?string $herkunft, bool $annahme): ValueSource
    {
        if ($annahme) {
            return ValueSource::SOLL_ANNAHME;
        }

        $quelle = $herkunft === null ? null : ValueSource::tryFrom($herkunft);

        return $quelle ?? ValueSource::MANUELL;
    }

    private function usagePeriod(Tenancy $tenancy, DatePeriodRange $period): ?DatePeriodRange
    {
        $end = $tenancy->ends_on instanceof Carbon ? $tenancy->ends_on : $period->end;

        if ($end < $tenancy->starts_on) {
            return null;
        }

        return $period->intersect(new DatePeriodRange($tenancy->starts_on, $end));
    }

    /**
     * @return list<Unit>
     */
    private function units(BillingRun $billingRun): array
    {
        $units = $billingRun->property->units
            ->sortBy(static fn (Unit $unit): string => $unit->label)
            ->values()
            ->all();

        /** @var list<Unit> $units */
        return $units;
    }

    /**
     * @return list<Tenancy>
     */
    private function tenancies(Unit $unit): array
    {
        $tenancies = $unit->tenancies
            ->sortBy(static fn (Tenancy $tenancy): string => $tenancy->starts_on->toDateString())
            ->values()
            ->all();

        /** @var list<Tenancy> $tenancies */
        return $tenancies;
    }
}
