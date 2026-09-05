<?php

declare(strict_types=1);

namespace App\Application\FollowUpYear;

use App\Application\Account\AuditRecorder;
use App\Application\Calculation\BillingRunInputAssembler;
use App\Domain\Allocation\AllocationKeyScope;
use App\Enums\AllocationKeySource;
use App\Enums\AllocationKeyType;
use App\Enums\BillingRunStatus;
use App\Enums\TenancyStatus;
use App\Enums\ValueSource;
use App\Models\AllocationKey;
use App\Models\AllocationKeyValue;
use App\Models\BillingRun;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Folgejahresuebernahme (Masterprompt 8.3).
 *
 * UEBERNOMMEN wird aus dem letzten FINALISIERTEN Lauf des Objekts:
 *
 *  - Objekt- und Eigentuemerdaten
 *  - Einheiten mit Flaechen und individuellen Schluesseln
 *  - laufende Mietverhaeltnisse, also solche ohne Auszug im neuen Zeitraum
 *  - Verteilerschluessel, mit Quellenkennzeichnung VORJAHR
 *  - Kostenkategorien
 *  - Bankverbindung und Absenderdaten ueber den Vermieterdatensatz
 *
 * NICHT UEBERNOMMEN wird:
 *
 *  - keine einzige Kostenposition. Vorjahreswerte dienen ausschliesslich dem
 *    Vergleich und niemals als neue Kosten. Der Vergleich wird nicht kopiert,
 *    sondern bei Bedarf ueber PriorYearComparison aus dem Vorjahreslauf
 *    gelesen.
 *  - keine Vorauszahlungen, keine Zaehlerstaende, keine Heizkostenabrechnung,
 *    kein Heizungsfall und kein Preisstand. Diese Angaben werden fuer das neue
 *    Jahr erneut erkannt beziehungsweise bestaetigt.
 *  - kein beendetes Mietverhaeltnis.
 *
 * Alle uebernommenen Verteilerschluessel tragen sichtbar den Hinweis
 * "Aus Vorjahr übernommen" und sind ausdruecklich unbestaetigt. Der Nutzer muss
 * sie erneut bestaetigen.
 *
 * Der Vorgang ist idempotent: Besteht fuer Objekt und Jahr bereits ein nicht
 * finalisierter Lauf, wird dieser zurueckgegeben und nicht ein zweiter angelegt.
 */
class CarryOverToFollowUpYear
{
    public const AUDIT_ACTION = 'billing_run.carried_over_from_previous_year';

    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Letzter finalisierter Lauf des Objekts.
     */
    public function letzterFinalisierterLauf(Property $objekt): ?BillingRun
    {
        /** @var BillingRun|null $lauf */
        $lauf = $objekt->billingRuns()
            ->where('status', BillingRunStatus::FINALIZED->value)
            ->orderByDesc('billing_year')
            ->orderByDesc('period_end')
            ->first();

        return $lauf;
    }

    /**
     * Bereits vorbereiteter, noch nicht finalisierter Lauf des Jahres.
     */
    public function bestehenderLauf(Property $objekt, int $jahr): ?BillingRun
    {
        /** @var BillingRun|null $lauf */
        $lauf = $objekt->billingRuns()
            ->where('billing_year', $jahr)
            ->whereNotIn('status', [
                BillingRunStatus::CANCELLED->value,
                BillingRunStatus::FINALIZED->value,
            ])
            ->orderByDesc('created_at')
            ->first();

        return $lauf;
    }

    /**
     * @throws KeinFinalisierterVorjahreslaufException
     */
    public function handle(Property $objekt, User $actor, ?int $jahr = null): CarryOverResult
    {
        $vorjahr = $this->letzterFinalisierterLauf($objekt);

        if (! $vorjahr instanceof BillingRun) {
            throw new KeinFinalisierterVorjahreslaufException(
                'Für dieses Objekt gibt es keine abgeschlossene Abrechnung, aus der übernommen werden kann.'
            );
        }

        $zieljahr = $jahr ?? ((int) $vorjahr->getAttribute('billing_year') + 1);

        $bestehend = $this->bestehenderLauf($objekt, $zieljahr);

        if ($bestehend instanceof BillingRun) {
            return new CarryOverResult(
                lauf: $bestehend,
                vorjahr: $vorjahr,
                neuAngelegt: false,
                einheiten: $this->einheiten($objekt),
                mietverhaeltnisse: $this->laufendeMietverhaeltnisse($objekt, $zieljahr),
                verteilerschluessel: $this->schluesselKennungen($bestehend),
                kostenkategorien: $this->kostenkategorien($vorjahr),
            );
        }

        $einheiten = $this->einheiten($objekt);
        $mietverhaeltnisse = $this->laufendeMietverhaeltnisse($objekt, $zieljahr);

        /** @var array{lauf: BillingRun, schluessel: list<string>} $ergebnis */
        $ergebnis = DB::transaction(function () use ($objekt, $actor, $vorjahr, $zieljahr, $mietverhaeltnisse): array {
            /** @var BillingRun $neu */
            $neu = BillingRun::query()->create([
                'organization_id' => $objekt->getAttribute('organization_id'),
                'created_by_user_id' => $actor->getKey(),
                'property_id' => $objekt->getKey(),
                // Bankverbindung und Absenderdaten des Vermieters
                'landlord_id' => $vorjahr->getAttribute('landlord_id') ?? $objekt->getAttribute('landlord_id'),
                'previous_billing_run_id' => $vorjahr->getKey(),
                'period_start' => sprintf('%04d-01-01', $zieljahr),
                'period_end' => sprintf('%04d-12-31', $zieljahr),
                'billing_year' => $zieljahr,
                'mode' => $vorjahr->getAttribute('mode'),
                'status' => BillingRunStatus::DRAFT,
                'wizard_step' => 1,
                // Heizungsfall, Preis und Zahlungsstand werden ausdruecklich
                // nicht uebernommen.
                'statement_count' => 0,
                'uploaded_bytes' => 0,
            ]);

            $schluessel = $this->uebernehmeVerteilerschluessel($vorjahr, $neu, $mietverhaeltnisse);

            return ['lauf' => $neu, 'schluessel' => $schluessel];
        });

        $organisation = $objekt->getAttribute('organization_id');

        $this->audit->record(
            action: self::AUDIT_ACTION,
            subject: $ergebnis['lauf'],
            actor: $actor,
            organization: is_string($organisation) ? $organisation : null,
            metadata: [
                'vorjahreslauf' => (string) $vorjahr->getKey(),
                'jahr' => $zieljahr,
                'einheiten' => count($einheiten),
                'mietverhaeltnisse' => count($mietverhaeltnisse),
                'verteilerschluessel' => count($ergebnis['schluessel']),
                'kostenpositionen' => 0,
            ],
        );

        return new CarryOverResult(
            lauf: $ergebnis['lauf'],
            vorjahr: $vorjahr,
            neuAngelegt: true,
            einheiten: $einheiten,
            mietverhaeltnisse: $mietverhaeltnisse,
            verteilerschluessel: $ergebnis['schluessel'],
            kostenkategorien: $this->kostenkategorien($vorjahr),
        );
    }

    /**
     * Einheiten des Objekts. Flaechen und individuelle Schluessel liegen
     * dauerhaft am Objekt und gelten damit auch im neuen Jahr.
     *
     * @return list<string>
     */
    private function einheiten(Property $objekt): array
    {
        $kennungen = [];

        foreach ($objekt->units()->orderBy('label')->get() as $einheit) {
            if ($einheit instanceof Unit && is_string($einheit->getKey())) {
                $kennungen[] = (string) $einheit->getKey();
            }
        }

        return $kennungen;
    }

    /**
     * Laufende Mietverhaeltnisse des neuen Zeitraums.
     *
     * Ein Mietverhaeltnis laeuft weiter, wenn es aktiv ist und entweder keinen
     * Auszug hat oder der Auszug im neuen Zeitraum oder danach liegt. Ein
     * beendetes Mietverhaeltnis wird nicht fortgeschrieben.
     *
     * @return list<string>
     */
    private function laufendeMietverhaeltnisse(Property $objekt, int $jahr): array
    {
        $beginn = sprintf('%04d-01-01', $jahr);

        $kennungen = [];

        $mietverhaeltnisse = $objekt->tenancies()
            ->where('status', TenancyStatus::AKTIV->value)
            ->where(function ($query) use ($beginn): void {
                $query->whereNull('ends_on')->orWhere('ends_on', '>=', $beginn);
            })
            ->orderBy('starts_on')
            ->get();

        foreach ($mietverhaeltnisse as $mietverhaeltnis) {
            if ($mietverhaeltnis instanceof Tenancy && is_string($mietverhaeltnis->getKey())) {
                $kennungen[] = (string) $mietverhaeltnis->getKey();
            }
        }

        return $kennungen;
    }

    /**
     * Uebernimmt die Verteilerschluessel mit Quellenkennzeichnung VORJAHR.
     *
     * Uebernommen werden ausschliesslich Schluessel, die an einer
     * Kostenkategorie haengen. Ein Schluessel, der nur fuer eine einzelne
     * Kostenposition des Vorjahres galt, wird nicht uebernommen, weil die
     * Position selbst nicht uebernommen wird.
     *
     * Werte werden nur fuer noch bestehende Einheiten und fortlaufende
     * Mietverhaeltnisse uebernommen, und nur fuer Schluessel der Bezugsebene
     * Einheit (Flaechen, Anteile, individuelle Werte). Fuer Schluessel der
     * Bezugsebene Nutzungszeitraum (Verbrauch, Personentage, Direktzuordnung)
     * wird ausschliesslich der Typ uebernommen: Verbrauchsmengen und Betraege
     * des Vorjahres duerfen niemals als Zaehler des neuen Jahres gelten und
     * werden fuer das neue Jahr neu erfasst.
     *
     * @param  list<string>  $laufendeMietverhaeltnisse
     * @return list<string>
     */
    private function uebernehmeVerteilerschluessel(
        BillingRun $vorjahr,
        BillingRun $neu,
        array $laufendeMietverhaeltnisse,
    ): array {
        $organisation = $neu->getAttribute('organization_id');
        $einheiten = $this->einheiten($neu->property);
        $neueKennungen = [];

        $vorlagen = $vorjahr->allocationKeys()
            ->whereNull('cost_item_id')
            ->whereNotNull('cost_category_id')
            ->with('values')
            ->get();

        foreach ($vorlagen as $vorlage) {
            $typ = $vorlage->getAttribute('key_type');
            $werteUebernehmen = $typ instanceof AllocationKeyType
                && BillingRunInputAssembler::scopeOf($typ) === AllocationKeyScope::UNIT;

            /** @var AllocationKey $kopie */
            $kopie = AllocationKey::query()->create([
                'organization_id' => $organisation,
                'billing_run_id' => $neu->getKey(),
                'cost_category_id' => $vorlage->getAttribute('cost_category_id'),
                'cost_item_id' => null,
                'key_type' => $vorlage->getAttribute('key_type'),
                // Sichtbare Quellenkennzeichnung
                'source' => AllocationKeySource::VORJAHR,
                'denominator' => $vorlage->getAttribute('denominator'),
                'measurement_unit' => $vorlage->getAttribute('measurement_unit'),
                'label' => $vorlage->getAttribute('label'),
                // Ausdruecklich unbestaetigt. Der Nutzer bestaetigt erneut.
                'confirmed_by_user_id' => null,
                'confirmed_at' => null,
                'note' => CarriedOver::HINWEIS,
            ]);

            $neueKennungen[] = (string) $kopie->getKey();

            if (! $werteUebernehmen) {
                continue;
            }

            foreach ($vorlage->values as $wert) {
                $einheitId = $wert->getAttribute('unit_id');
                $mietverhaeltnisId = $wert->getAttribute('tenancy_id');

                if (is_string($einheitId) && ! in_array($einheitId, $einheiten, true)) {
                    continue;
                }

                if (is_string($mietverhaeltnisId)
                    && ! in_array($mietverhaeltnisId, $laufendeMietverhaeltnisse, true)) {
                    continue;
                }

                AllocationKeyValue::query()->create([
                    'organization_id' => $organisation,
                    'allocation_key_id' => $kopie->getKey(),
                    'unit_id' => $einheitId,
                    'tenancy_id' => $mietverhaeltnisId,
                    'numerator' => $wert->getAttribute('numerator'),
                    'valid_from' => null,
                    'valid_to' => null,
                    'source' => ValueSource::VORJAHR,
                ]);
            }
        }

        return $neueKennungen;
    }

    /**
     * @return list<string>
     */
    private function schluesselKennungen(BillingRun $lauf): array
    {
        $kennungen = [];

        foreach ($lauf->allocationKeys()->get() as $schluessel) {
            $kennungen[] = (string) $schluessel->getKey();
        }

        return $kennungen;
    }

    /**
     * Kostenkategorien, die im Vorjahr verwendet wurden. Sie werden im neuen
     * Jahr vorbelegt, ohne dass ein Betrag uebernommen wird.
     *
     * @return list<string>
     */
    private function kostenkategorien(BillingRun $vorjahr): array
    {
        /** @var list<string> $ausPositionen */
        $ausPositionen = $vorjahr->costItems()
            ->whereNotNull('cost_category_id')
            ->distinct()
            ->pluck('cost_category_id')
            ->filter(fn (mixed $wert): bool => is_string($wert))
            ->values()
            ->all();

        /** @var list<string> $ausSchluesseln */
        $ausSchluesseln = $vorjahr->allocationKeys()
            ->whereNotNull('cost_category_id')
            ->distinct()
            ->pluck('cost_category_id')
            ->filter(fn (mixed $wert): bool => is_string($wert))
            ->values()
            ->all();

        return array_values(array_unique([...$ausPositionen, ...$ausSchluesseln]));
    }
}
