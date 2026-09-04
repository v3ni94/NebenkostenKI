<?php

declare(strict_types=1);

namespace App\Application\Wizard;

use App\Application\Payment\CancelCheckout;
use App\Enums\BillingRunStatus;
use App\Models\BillingRun;
use App\Models\Landlord;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Zentrale Invalidierung der Vorschau bei Stammdatenaenderungen.
 *
 * Aenderungen an Objekt, Vermieter, Einheiten, Mietverhaeltnissen, Belegungen
 * und Leerstaenden sind abrechnungsrelevant (PreviewBuilder Regel 3). Fuer
 * jeden noch nicht bezahlten Lauf des Objekts, dessen Zeitraum betroffen ist,
 * werden die Vorschaudokumente auf UNGUELTIG gesetzt, die Nutzerbestaetigung
 * zurueckgenommen und veraltete Sollwerte der Vorauszahlungen bereinigt.
 *
 * Ein Lauf in CHECKOUT_PENDING verliert zusaetzlich seinen offenen
 * Zahlungsvorgang: Die Zahlungsseite beim Anbieter wird beendet, die Zahlungen
 * werden abgebrochen und der Lauf kehrt ueber die Statusmaschine nach
 * PREVIEW_READY zurueck. Ein Kunde darf niemals einen Berechnungsstand
 * bezahlen und erhalten, der nicht der bestaetigten Vorschau entspricht.
 *
 * Bezahlte und finalisierte Laeufe bleiben unangetastet: Ihr Berechnungsstand
 * ist gesperrt, eine nachtraegliche Aenderung der Stammdaten wirkt dort nicht.
 */
final class PreviewInvalidator
{
    public function __construct(
        private readonly PreviewBuilder $preview,
        private readonly ReviewConfirmation $confirmation,
        private readonly PrepaymentWorkspace $prepayments,
        private readonly CancelCheckout $cancelCheckout,
    ) {}

    /**
     * Alle offenen Laeufe eines Objekts.
     *
     * @return int Anzahl der invalidierten Laeufe
     */
    public function forProperty(Property $property, ?User $actor = null): int
    {
        return $this->invalidate($this->openRuns((string) $property->getKey()), $actor);
    }

    /**
     * Alle offenen Laeufe der Objekte eines Vermieters. Der Vermieter ist
     * Absender der Mieterabrechnung, seine Angaben stehen auf jeder Seite.
     */
    public function forLandlord(Landlord $landlord, ?User $actor = null): int
    {
        $anzahl = 0;

        foreach ($landlord->properties()->get() as $property) {
            $anzahl += $this->forProperty($property, $actor);
        }

        return $anzahl;
    }

    /**
     * Alle offenen Laeufe des Objekts der Einheit.
     */
    public function forUnit(Unit $unit, ?User $actor = null): int
    {
        $propertyId = $unit->getAttribute('property_id');

        if (! is_string($propertyId) || $propertyId === '') {
            return 0;
        }

        return $this->invalidate($this->openRuns($propertyId), $actor);
    }

    /**
     * Offene Laeufe des Objekts, deren Zeitraum das Mietverhaeltnis beruehrt.
     * Bei einer Bearbeitung uebergibt der Aufrufer zusaetzlich den bisherigen
     * Vertragszeitraum, damit ein verschobener Einzug den bisher betroffenen
     * Lauf ebenfalls invalidiert.
     *
     * @param  array{0: string|null, 1: string|null}|null  $bisherigerZeitraum  Einzug und Auszug (ISO) vor der Aenderung
     */
    public function forTenancy(Tenancy $tenancy, ?User $actor = null, ?array $bisherigerZeitraum = null): int
    {
        $propertyId = $tenancy->getAttribute('property_id');

        if (! is_string($propertyId) || $propertyId === '') {
            return 0;
        }

        $zeitraeume = [
            [$this->iso($tenancy->getAttribute('starts_on')), $this->iso($tenancy->getAttribute('ends_on'))],
        ];

        if ($bisherigerZeitraum !== null) {
            $zeitraeume[] = $bisherigerZeitraum;
        }

        $betroffen = array_values(array_filter(
            $this->openRuns($propertyId),
            fn (BillingRun $run): bool => $this->touches($run, $zeitraeume)
        ));

        return $this->invalidate($betroffen, $actor);
    }

    /**
     * @param  list<BillingRun>  $runs
     */
    private function invalidate(array $runs, ?User $actor): int
    {
        foreach ($runs as $run) {
            if ($run->getAttribute('status') === BillingRunStatus::CHECKOUT_PENDING) {
                ($this->cancelCheckout)($run, $actor, CancelCheckout::VORSCHAU_UNGUELTIG);
            }

            $this->preview->invalidate($run);
            $this->confirmation->reset($run);
            $this->prepayments->refreshStoredTargets($run, $actor);
        }

        return count($runs);
    }

    /**
     * Laeufe des Objekts, die noch nicht bezahlt sind. Abgebrochene Laeufe
     * bleiben aussen vor, sie werden nicht mehr fortgefuehrt.
     *
     * @return list<BillingRun>
     */
    private function openRuns(string $propertyId): array
    {
        $runs = BillingRun::query()
            ->where('property_id', $propertyId)
            ->whereNotIn('status', [
                BillingRunStatus::PAID->value,
                BillingRunStatus::FINALIZING->value,
                BillingRunStatus::FINALIZED->value,
                BillingRunStatus::CANCELLED->value,
            ])
            ->get()
            ->all();

        /** @var list<BillingRun> $runs */
        return $runs;
    }

    /**
     * @param  list<array{0: string|null, 1: string|null}>  $zeitraeume
     */
    private function touches(BillingRun $run, array $zeitraeume): bool
    {
        $periodStart = $this->iso($run->getAttribute('period_start'));
        $periodEnd = $this->iso($run->getAttribute('period_end'));

        if ($periodStart === null || $periodEnd === null) {
            return true;
        }

        foreach ($zeitraeume as [$start, $ende]) {
            if ($start === null) {
                return true;
            }

            if ($start <= $periodEnd && ($ende === null || $ende >= $periodStart)) {
                return true;
            }
        }

        return false;
    }

    private function iso(mixed $wert): ?string
    {
        if ($wert instanceof Carbon) {
            return $wert->toDateString();
        }

        if (is_string($wert) && $wert !== '') {
            return substr($wert, 0, 10);
        }

        return null;
    }
}
