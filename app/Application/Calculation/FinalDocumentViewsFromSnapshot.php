<?php

declare(strict_types=1);

namespace App\Application\Calculation;

use App\Application\Calculation\Dto\AssembledCalculationInput;
use App\Application\Payment\Contracts\FinalDocumentViews;
use App\Application\Payment\Dto\FinalViewBundle;
use App\Application\Payment\Exceptions\FinalizationFailedException;
use App\Application\Wizard\StatementViewFactory;
use App\Domain\Calculation\Dto\OccupancyInput;
use App\Domain\Calculation\Dto\StatementCalculationInput;
use App\Domain\Calculation\OccupancyKind;
use App\Domain\Calculation\Result\UnitStatementResult;
use App\Models\BillingRun;
use App\Models\CalculationSnapshot;
use App\Models\UnitStatement;
use App\Models\UnitStatementLine;

/**
 * Aufbereitung eines gesperrten Berechnungsstandes zu Darstellungsobjekten.
 *
 * VERBINDLICHE REGELN (Masterprompt Abschnitt 9 Schritt 10 bis 12, 14.3)
 *
 *  1. GENAU DERSELBE WEG WIE DIE VORSCHAU. Die Vorschau erzeugt ihre
 *     Darstellungsobjekte ueber StatementViewFactory aus einem
 *     CalculationRunResult. Diese Klasse tut dasselbe und verwendet dieselbe
 *     Fabrik. Damit koennen Vorschau und Finalversion inhaltlich nicht
 *     auseinanderlaufen: die Darstellung entsteht aus einer Quelle.
 *  2. DER SNAPSHOT IST DIE QUELLE. Das Ergebnis wird ausschliesslich ueber
 *     RecalculateFromSnapshot aus der im Snapshot gespeicherten normalisierten
 *     Eingabe reproduziert. Es wird NICHT aus den aktuellen Modellen neu
 *     zusammengestellt und NICHT geschaetzt. Eine spaetere Aenderung an
 *     Kostenpositionen, Schluesseln oder Vorauszahlungen aendert die bezahlte
 *     Finalversion deshalb nicht.
 *  3. KEINE ERSATZWERTE. Traegt der Snapshot keine vollstaendige
 *     normalisierte Eingabe, bricht die Finalisierung ab
 *     (FinalizationFailedException). Ist die Eingabe vorhanden, aber nicht
 *     lesbar, bricht RecalculateFromSnapshot mit CalculationInputException ab.
 *     Es entsteht in keinem Fall ein ersatzweise berechneter Betrag.
 *  4. Die Finalversion wird vollstaendig neu erzeugt. Diese Klasse liefert nur
 *     Darstellungsobjekte; sie liest, kopiert und veraendert keine
 *     Vorschaudatei.
 *
 * ZUORDNUNGEN AUS DEM SNAPSHOT STATT AUS DEN AKTUELLEN MODELLEN
 *
 * StatementViewFactory benoetigt neben dem Ergebnis die Zuordnung von
 * Berechnungsschluesseln zu Datensaetzen. Sie wird hier ausschliesslich aus
 * bereits festgeschriebenen Daten gebildet und nicht neu ermittelt:
 *
 *  - Einheiten- und Belegungsschluessel sind die Kennungen der Einheit
 *    beziehungsweise des Mietverhaeltnisses (BillingRunInputAssembler). Die
 *    Zuordnung ist damit unmittelbar im Snapshot enthalten.
 *  - Die Kostenart je Position steht als categoryKey in der Eingabe des
 *    Snapshots.
 *  - Welche Kostenarten in den Heizkostenblock gehoeren, steht in den zum
 *    Snapshot geschriebenen Rechenzeilen (Merkmal is_heating_line). Auch das
 *    ist ein festgeschriebener Stand des Zeitpunkts der Berechnung.
 *
 * Stammdaten fuer Absender, Empfaenger und Objektangaben kommen wie in der
 * Vorschau aus den Modellen. Sie sind Anschriften und keine Rechenwerte.
 */
final class FinalDocumentViewsFromSnapshot implements FinalDocumentViews
{
    public function __construct(
        private readonly RecalculateFromSnapshot $recalculate,
        private readonly StatementViewFactory $views,
    ) {}

    public function forSnapshot(CalculationSnapshot $snapshot): FinalViewBundle
    {
        $billingRun = $this->billingRun($snapshot);

        $this->assertVerwertbar($snapshot);

        // Quelle ist ausschliesslich der Snapshot.
        $input = $this->recalculate->input($snapshot);
        $result = $this->recalculate->handle($snapshot);

        $assembled = $this->assembled($snapshot, $input);

        $statements = $this->views->tenantViews($billingRun, $result, $assembled);
        $statementIds = $this->statementIds($snapshot, $result->statements);

        return new FinalViewBundle(
            $statements,
            $statementIds,
            $this->views->ownerOverviewView($billingRun, $result),
        );
    }

    /**
     * Traegt der Snapshot eine vollstaendige normalisierte Eingabe?
     *
     * Fehlt sie oder ist sie unvollstaendig, bricht die Finalisierung mit der
     * fachlichen Meldung ab. Es wird ausdruecklich nichts ergaenzt und nichts
     * aus den aktuellen Modellen nachgeladen.
     */
    private function assertVerwertbar(CalculationSnapshot $snapshot): void
    {
        $payload = $snapshot->getAttribute('input');

        if (! is_array($payload)) {
            throw FinalizationFailedException::snapshotMissing();
        }

        foreach (['billingPeriod', 'units', 'occupancies', 'costItems', 'allocationKeys', 'prepayments'] as $key) {
            if (! array_key_exists($key, $payload)) {
                throw FinalizationFailedException::snapshotMissing();
            }
        }
    }

    private function billingRun(CalculationSnapshot $snapshot): BillingRun
    {
        $snapshot->loadMissing('billingRun.property.units.tenancies', 'billingRun.landlord');

        $billingRun = $snapshot->getRelationValue('billingRun');

        if (! $billingRun instanceof BillingRun) {
            throw CalculationInputException::snapshotNotReproducible((string) $snapshot->getKey());
        }

        return $billingRun;
    }

    /**
     * Zuordnungen des festgeschriebenen Standes, ohne Neuermittlung.
     */
    private function assembled(
        CalculationSnapshot $snapshot,
        StatementCalculationInput $input,
    ): AssembledCalculationInput {
        $tenancyIds = [];

        foreach ($input->occupancies as $occupancy) {
            if (! $occupancy instanceof OccupancyInput || $occupancy->kind !== OccupancyKind::TENANCY) {
                continue;
            }

            // Der Belegungsschluessel IST die Kennung des Mietverhaeltnisses.
            $tenancyIds[$occupancy->occupancyKey] = $occupancy->occupancyKey;
        }

        $unitIds = [];

        foreach ($input->units as $unit) {
            $unitIds[$unit->unitKey] = $unit->unitKey;
        }

        $categoryIds = [];

        foreach ($input->costItems as $item) {
            $categoryIds[$item->costItemKey] = $item->categoryKey === '' ? null : $item->categoryKey;
        }

        return new AssembledCalculationInput(
            $input,
            $tenancyIds,
            $unitIds,
            $categoryIds,
            $this->heatingCategoryKeys($snapshot),
        );
    }

    /**
     * Kostenarten des Heizkostenblocks aus den Rechenzeilen des Snapshots.
     *
     * @return list<string>
     */
    private function heatingCategoryKeys(CalculationSnapshot $snapshot): array
    {
        /** @var list<string> $keys */
        $keys = UnitStatementLine::query()
            ->whereIn(
                'unit_statement_id',
                UnitStatement::query()
                    ->select('id')
                    ->where('calculation_snapshot_id', $snapshot->getKey())
            )
            ->where('is_heating_line', true)
            ->whereNotNull('cost_category_id')
            ->distinct()
            ->pluck('cost_category_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        return $keys;
    }

    /**
     * Kennung der Mieterabrechnung je Ergebniszeile, in genau der Reihenfolge
     * der Abrechnungen des Ergebnisses.
     *
     * @param  list<UnitStatementResult>  $statements
     * @return list<string|null>
     */
    private function statementIds(CalculationSnapshot $snapshot, array $statements): array
    {
        /** @var array<string, string> $byTenancy */
        $byTenancy = UnitStatement::query()
            ->where('calculation_snapshot_id', $snapshot->getKey())
            ->pluck('id', 'tenancy_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $ids = [];

        foreach ($statements as $statement) {
            $ids[] = $byTenancy[$statement->occupancyKey] ?? null;
        }

        return $ids;
    }
}
