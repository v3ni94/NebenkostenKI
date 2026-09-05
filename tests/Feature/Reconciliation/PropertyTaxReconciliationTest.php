<?php

declare(strict_types=1);

namespace Tests\Feature\Reconciliation;

use App\Application\Reconciliation\ReconcileBillingRun;
use App\Application\Reconciliation\RuleCode;
use App\Enums\DocumentType;
use App\Models\BillingRun;
use App\Models\CostItem;
use App\Models\Document;
use App\Models\ValidationIssue;
use Tests\Feature\Review\ReviewTestCase;

/**
 * Grundsteuer nach Abschnitt 7.3.
 */
final class PropertyTaxReconciliationTest extends ReviewTestCase
{
    public function test_separate_grundsteuer_wird_als_direkte_position_uebernommen(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $bescheid = $this->grundsteuer($lauf);

        app(ReconcileBillingRun::class)->run($lauf);

        $position = CostItem::query()->where('document_id', $bescheid->getKey())->firstOrFail();

        self::assertSame(43200, $position->getAttribute('amount_cent'));
        self::assertStringContainsString('Grundsteuer', (string) $position->getAttribute('description'));
        self::assertStringContainsString('GR-2025-77', (string) $position->getAttribute('description'));
        self::assertSame('GR-2025-77', $position->getAttribute('invoice_number'));
    }

    public function test_grundsteuer_in_der_hausgeldabrechnung_wird_nicht_addiert(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $weg = $this->dokument($lauf, DocumentType::WEG_HAUSGELDABRECHNUNG_EINZEL, 'Unterlage WEG');
        $this->felder($weg, [
            'einheitsbezeichnung' => 'Wohnung 3',
            'abrechnungszeitraum_von' => '2025-01-01',
            'abrechnungszeitraum_bis' => '2025-12-31',
            'grundsteuer_enthalten' => true,
            'kostenarten[0].bezeichnung' => 'Grundsteuer',
            'kostenarten[0].kategorie' => 'BETRIEBSKOSTEN',
            'kostenarten[0].anteil_einheit_cent' => 43200,
        ]);

        $bescheid = $this->grundsteuer($lauf);

        $ergebnis = app(ReconcileBillingRun::class)->run($lauf);

        self::assertNotNull($ergebnis->propertyTax);
        self::assertFalse($ergebnis->propertyTax->added);
        self::assertTrue($ergebnis->propertyTax->possibleDuplicate);

        self::assertSame(0, CostItem::query()->where('document_id', $bescheid->getKey())->count());
        self::assertSame(43200, (int) CostItem::query()->where('billing_run_id', $lauf->getKey())->sum('amount_cent'));

        $aufgabe = ValidationIssue::query()
            ->where('billing_run_id', $lauf->getKey())
            ->where('rule_code', RuleCode::PROPERTY_TAX_POSSIBLE_DUPLICATE)
            ->firstOrFail();

        self::assertStringContainsString('nicht zusätzlich angesetzt', (string) $aufgabe->getAttribute('description'));
    }

    public function test_grundsteuer_in_anderer_kostenliste_wird_nicht_addiert(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $rechnung = $this->dokument($lauf, DocumentType::RECHNUNG, 'Unterlage Abgabenbescheid');
        $this->felder($rechnung, [
            'belegdatum' => '2025-02-01',
            'gesamtbetrag_brutto_cent' => 43200,
            'vorgeschlagene_kostenart' => 'Grundsteuer',
            'leistungszeitraum_von' => '2025-01-01',
            'leistungszeitraum_bis' => '2025-12-31',
        ]);

        $bescheid = $this->grundsteuer($lauf);

        $ergebnis = app(ReconcileBillingRun::class)->run($lauf);

        self::assertNotNull($ergebnis->propertyTax);
        self::assertTrue($ergebnis->propertyTax->possibleDuplicate);
        self::assertSame(0, CostItem::query()->where('document_id', $bescheid->getKey())->count());
    }

    public function test_teilzeitraum_wird_nicht_geraten_sondern_vorgelegt(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $bescheid = $this->grundsteuer($lauf, ['betrifft_teilzeitraum' => true]);

        $ergebnis = app(ReconcileBillingRun::class)->run($lauf);

        self::assertNotNull($ergebnis->propertyTax);
        self::assertFalse($ergebnis->propertyTax->added);
        self::assertTrue($ergebnis->propertyTax->needsPeriodConfirmation);
        self::assertSame(0, CostItem::query()->where('document_id', $bescheid->getKey())->count());

        self::assertTrue(
            ValidationIssue::query()
                ->where('billing_run_id', $lauf->getKey())
                ->where('rule_code', RuleCode::PROPERTY_TAX_NEEDS_CONFIRMATION)
                ->exists()
        );
    }

    public function test_unbekannter_zeitraum_wird_nicht_dem_abrechnungszeitraum_gleichgesetzt(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        // Betrag und Aktenzeichen wurden ausgelesen, Zeitraum und Steuerjahr
        // nicht. Vorher entstand eine Position mit Leistungszeitraum 2025 und
        // Prüfergebnis "übernommen".
        $bescheid = $this->grundsteuer($lauf, [
            'steuerjahr' => null,
            'zeitraum_von' => null,
            'zeitraum_bis' => null,
        ]);

        $ergebnis = app(ReconcileBillingRun::class)->run($lauf);

        self::assertNotNull($ergebnis->propertyTax);
        self::assertFalse($ergebnis->propertyTax->added);
        self::assertTrue($ergebnis->propertyTax->needsPeriodConfirmation);
        self::assertSame(0, CostItem::query()->where('document_id', $bescheid->getKey())->count());

        self::assertTrue(
            ValidationIssue::query()
                ->where('billing_run_id', $lauf->getKey())
                ->where('rule_code', RuleCode::PROPERTY_TAX_NEEDS_CONFIRMATION)
                ->exists()
        );
    }

    public function test_steuerjahr_allein_genuegt_fuer_die_uebernahme(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $bescheid = $this->grundsteuer($lauf, ['zeitraum_von' => null, 'zeitraum_bis' => null]);

        app(ReconcileBillingRun::class)->run($lauf);

        $position = CostItem::query()->where('document_id', $bescheid->getKey())->firstOrFail();

        self::assertSame(43200, $position->getAttribute('amount_cent'));
        self::assertSame('2025-01-01', $position->getAttribute('service_period_start')?->format('Y-m-d'));
        self::assertSame('2025-12-31', $position->getAttribute('service_period_end')?->format('Y-m-d'));
    }

    public function test_eigentumswechsel_wird_nicht_geraten(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $bescheid = $this->grundsteuer($lauf, ['eigentumswechsel_erwaehnt' => true]);

        app(ReconcileBillingRun::class)->run($lauf);

        self::assertSame(0, CostItem::query()->where('document_id', $bescheid->getKey())->count());
    }

    public function test_bescheid_ohne_einheit_wird_nicht_uebernommen(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $bescheid = $this->grundsteuer($lauf, ['einheitsbezeichnung' => null]);

        $ergebnis = app(ReconcileBillingRun::class)->run($lauf);

        self::assertNotNull($ergebnis->propertyTax);
        self::assertFalse($ergebnis->propertyTax->added);
        self::assertSame(0, CostItem::query()->where('document_id', $bescheid->getKey())->count());
    }

    public function test_fehlender_jahresbetrag_erzeugt_pruefaufgabe(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->grundsteuer($lauf, ['jahresbetrag_cent' => null]);

        app(ReconcileBillingRun::class)->run($lauf);

        self::assertTrue(
            ValidationIssue::query()
                ->where('billing_run_id', $lauf->getKey())
                ->where('rule_code', RuleCode::MISSING_MANDATORY)
                ->where('title', 'Angabe fehlt: Grundsteuer-Jahresbetrag')
                ->exists()
        );
    }

    /**
     * @param  array<string, mixed>  $ueberschreiben
     */
    private function grundsteuer(BillingRun $lauf, array $ueberschreiben = []): Document
    {
        $dokument = $this->dokument($lauf, DocumentType::GRUNDSTEUERBESCHEID, 'Unterlage Grundsteuer');

        $this->felder($dokument, array_merge([
            'behoerde' => 'Stadtkasse Beispielstadt',
            'bescheiddatum' => '2025-01-15',
            'aktenzeichen' => 'GR-2025-77',
            'einheitsbezeichnung' => 'Wohnung 3',
            'steuerjahr' => 2025,
            'zeitraum_von' => '2025-01-01',
            'zeitraum_bis' => '2025-12-31',
            'jahresbetrag_cent' => 43200,
            'betrifft_teilzeitraum' => false,
            'eigentumswechsel_erwaehnt' => false,
        ], $ueberschreiben));

        return $dokument;
    }
}
