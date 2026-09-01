<?php

declare(strict_types=1);

namespace Tests\Feature\Reconciliation;

use App\Application\Reconciliation\Dto\HeatingSourceKind;
use App\Application\Reconciliation\HeatingReconciler;
use App\Application\Reconciliation\ReconcileBillingRun;
use App\Application\Reconciliation\RuleCode;
use App\Enums\BillingMode;
use App\Enums\DocumentType;
use App\Models\BillingRun;
use App\Models\CostItem;
use App\Models\Document;
use App\Models\ValidationIssue;
use Tests\Feature\Review\ReviewTestCase;

/**
 * Heizkosten und Doppelzaehlung nach Abschnitt 7.4.
 */
final class HeatingReconciliationTest extends ReviewTestCase
{
    public function test_externe_abrechnung_verhindert_ansatz_der_weg_summenposition(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $weg = $this->wegMitHeizkosten($lauf);
        $this->externeAbrechnung($lauf);

        $ergebnis = app(ReconcileBillingRun::class)->run($lauf);

        $bezeichnungen = CostItem::query()
            ->where('document_id', $weg->getKey())
            ->pluck('description')
            ->all();

        self::assertNotContains('Heizkosten', $bezeichnungen);
        self::assertTrue($ergebnis->heatingMatrix->externalStatementPresent);

        self::assertTrue(
            ValidationIssue::query()
                ->where('billing_run_id', $lauf->getKey())
                ->where('rule_code', RuleCode::HEATING_DOUBLE_COUNT_PREVENTED)
                ->exists()
        );
    }

    public function test_ohne_externe_abrechnung_ist_die_weg_position_kostenquelle(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->wegMitHeizkosten($lauf);

        $matrix = app(HeatingReconciler::class)->matrix($lauf, app(ReconcileBillingRun::class)->documents($lauf));

        self::assertFalse($matrix->externalStatementPresent);

        $zeilen = array_values(array_filter(
            $matrix->rows,
            static fn ($zeile): bool => $zeile->sourceKind === HeatingSourceKind::HAUSGELD_HEIZKOSTEN
        ));

        self::assertNotSame([], $zeilen);
        self::assertTrue($zeilen[0]->applied);
        self::assertStringContainsString('Kostenquelle', $zeilen[0]->treatment);
    }

    public function test_abweichung_ueber_der_toleranz_blockiert_die_finalisierung(): void
    {
        config()->set('smartabrechnen.tolerances.checksum_cent', 100);

        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->externeAbrechnung($lauf, 120000, [60000, 30000]);

        $ergebnis = app(ReconcileBillingRun::class)->run($lauf);

        self::assertFalse($ergebnis->heatingMatrix->withinTolerance);
        self::assertTrue($ergebnis->heatingMatrix->blocksFinalization);
        self::assertTrue($ergebnis->blocksFinalization);

        $aufgabe = ValidationIssue::query()
            ->where('billing_run_id', $lauf->getKey())
            ->where('rule_code', RuleCode::HEATING_CHECKSUM_OUT_OF_TOLERANCE)
            ->firstOrFail();

        self::assertTrue($aufgabe->getAttribute('blocks_finalization'));
    }

    public function test_abweichung_innerhalb_der_toleranz_blockiert_nicht(): void
    {
        config()->set('smartabrechnen.tolerances.checksum_cent', 100);

        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->externeAbrechnung($lauf, 90050, [60000, 30000]);

        $ergebnis = app(ReconcileBillingRun::class)->run($lauf);

        self::assertTrue($ergebnis->heatingMatrix->withinTolerance);
        self::assertFalse($ergebnis->heatingMatrix->blocksFinalization);

        self::assertFalse(
            ValidationIssue::query()
                ->where('billing_run_id', $lauf->getKey())
                ->where('rule_code', RuleCode::HEATING_CHECKSUM_OUT_OF_TOLERANCE)
                ->exists()
        );
    }

    public function test_matrix_enthaelt_quelle_betrag_einheit_zeitraum_und_behandlung(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->wegMitHeizkosten($lauf);
        $this->externeAbrechnung($lauf);

        $matrix = app(HeatingReconciler::class)->matrix($lauf, app(ReconcileBillingRun::class)->documents($lauf));

        self::assertTrue($matrix->hasRows());

        foreach ($matrix->rows as $zeile) {
            self::assertNotSame('', $zeile->unitLabel);
            self::assertNotSame('', $zeile->periodLabel);
            self::assertNotSame('', $zeile->treatment);
        }

        $arten = array_map(static fn ($zeile) => $zeile->sourceKind, $matrix->rows);

        self::assertContains(HeatingSourceKind::HAUSGELD_HEIZKOSTEN, $arten);
        self::assertContains(HeatingSourceKind::EXTERNE_EINZELABRECHNUNG, $arten);
    }

    public function test_brennstoffrechnung_wird_nur_im_vollobjektmodus_angesetzt(): void
    {
        $mandant = $this->mandant();

        $schnell = $this->lauf($mandant['organization'], $mandant['property']);
        $this->brennstoff($schnell);

        $matrixSchnell = app(HeatingReconciler::class)
            ->matrix($schnell, app(ReconcileBillingRun::class)->documents($schnell));

        $zeileSchnell = $this->zeile($matrixSchnell->rows, HeatingSourceKind::BRENNSTOFFRECHNUNG);

        self::assertFalse($zeileSchnell->applied);
        self::assertStringContainsString('Objektabrechnung', $zeileSchnell->treatment);

        $voll = $this->lauf($mandant['organization'], $mandant['property'], ['mode' => BillingMode::FULL_PROPERTY]);
        $this->brennstoff($voll);

        $matrixVoll = app(HeatingReconciler::class)
            ->matrix($voll, app(ReconcileBillingRun::class)->documents($voll));

        self::assertTrue($this->zeile($matrixVoll->rows, HeatingSourceKind::BRENNSTOFFRECHNUNG)->applied);
    }

    public function test_fehlender_gesamtbetrag_der_heizkostenabrechnung_erzeugt_pruefaufgabe(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $dokument = $this->dokument($lauf, DocumentType::HEIZKOSTENABRECHNUNG, 'Unterlage Heizkosten');
        $this->felder($dokument, [
            'abrechnungsdienst' => 'Abrechnungsdienst Beispiel',
            'abrechnungszeitraum_von' => '2025-01-01',
            'abrechnungszeitraum_bis' => '2025-12-31',
            'gesamtkosten_summe_cent' => null,
            'einheiten[0].einheitsbezeichnung' => 'Wohnung 3',
            'einheiten[0].summe_cent' => 60000,
        ]);

        app(ReconcileBillingRun::class)->run($lauf);

        self::assertTrue(
            ValidationIssue::query()
                ->where('billing_run_id', $lauf->getKey())
                ->where('rule_code', RuleCode::MISSING_MANDATORY)
                ->where('title', 'Angabe fehlt: Gesamtbetrag der Heizkostenabrechnung')
                ->exists()
        );
    }

    public function test_toleranz_kommt_aus_der_konfiguration(): void
    {
        config()->set('smartabrechnen.tolerances.checksum_cent', 250);

        self::assertSame(250, HeatingReconciler::toleranceCent());
    }

    /**
     * @param  list<HeatingSourceKind>|array<int, mixed>  $zeilen
     */
    private function zeile(array $zeilen, HeatingSourceKind $art): object
    {
        $treffer = array_values(array_filter(
            $zeilen,
            static fn ($zeile): bool => $zeile->sourceKind === $art
        ));

        self::assertNotSame([], $treffer);

        return $treffer[0];
    }

    private function wegMitHeizkosten(BillingRun $lauf): Document
    {
        $dokument = $this->dokument($lauf, DocumentType::WEG_HAUSGELDABRECHNUNG_EINZEL, 'Unterlage WEG');

        $this->felder($dokument, [
            'einheitsbezeichnung' => 'Wohnung 3',
            'abrechnungszeitraum_von' => '2025-01-01',
            'abrechnungszeitraum_bis' => '2025-12-31',
            'heizkosten_anteil_einheit_cent' => 90000,
            'kostenarten[0].bezeichnung' => 'Heizkosten',
            'kostenarten[0].kategorie' => 'HEIZUNG_WARMWASSER',
            'kostenarten[0].anteil_einheit_cent' => 90000,
            'kostenarten[1].bezeichnung' => 'Gartenpflege',
            'kostenarten[1].kategorie' => 'BETRIEBSKOSTEN',
            'kostenarten[1].anteil_einheit_cent' => 24000,
        ]);

        return $dokument;
    }

    /**
     * @param  list<int>  $einzelbetraege
     */
    private function externeAbrechnung(BillingRun $lauf, int $gesamt = 90000, array $einzelbetraege = [60000, 30000]): Document
    {
        $dokument = $this->dokument($lauf, DocumentType::HEIZKOSTENABRECHNUNG, 'Unterlage Heizkosten');

        $felder = [
            'abrechnungsdienst' => 'Abrechnungsdienst Beispiel',
            'abrechnungszeitraum_von' => '2025-01-01',
            'abrechnungszeitraum_bis' => '2025-12-31',
            'gesamtkosten_summe_cent' => $gesamt,
            'co2_kostenaufteilung_status' => 'ENTHALTEN',
        ];

        foreach ($einzelbetraege as $index => $betrag) {
            $felder[sprintf('einheiten[%d].einheitsbezeichnung', $index)] = sprintf('Wohnung %d', $index + 1);
            $felder[sprintf('einheiten[%d].summe_cent', $index)] = $betrag;
            $felder[sprintf('einheiten[%d].nutzungszeitraum_von', $index)] = '2025-01-01';
            $felder[sprintf('einheiten[%d].nutzungszeitraum_bis', $index)] = '2025-12-31';
        }

        $this->felder($dokument, $felder);

        return $dokument;
    }

    private function brennstoff(BillingRun $lauf): Document
    {
        $dokument = $this->dokument($lauf, DocumentType::ENERGIE_BRENNSTOFFRECHNUNG, 'Unterlage Brennstoff');

        $this->felder($dokument, [
            'aussteller' => 'Energie Beispiel',
            'belegdatum' => '2025-10-01',
            'gesamtbetrag_brutto_cent' => 250000,
            'leistungszeitraum_von' => '2025-01-01',
            'leistungszeitraum_bis' => '2025-12-31',
            'vorgeschlagene_kostenart' => 'Heizkosten',
        ]);

        return $dokument;
    }
}
