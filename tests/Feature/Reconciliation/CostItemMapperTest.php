<?php

declare(strict_types=1);

namespace Tests\Feature\Reconciliation;

use App\Application\Reconciliation\CostItemMapper;
use App\Application\Reconciliation\CostItemProposalWriter;
use App\Application\Reconciliation\ReconcileBillingRun;
use App\Application\Reconciliation\RuleCode;
use App\Enums\ApportionmentStatus;
use App\Enums\CostItemSource;
use App\Enums\CostItemStatus;
use App\Enums\DocumentType;
use App\Enums\Paragraph35aType;
use App\Models\CostItem;
use App\Models\ValidationIssue;
use Tests\Feature\Review\ReviewTestCase;

/**
 * Zuordnung ausgelesener Inhaltsdaten zu Kostenpositionen.
 *
 * Grundsatz: nur Vorschlaege, keine Schaetzungen, keine automatische
 * Bestaetigung.
 */
final class CostItemMapperTest extends ReviewTestCase
{
    public function test_mapper_erzeugt_ausschliesslich_vorgeschlagene_positionen(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $dokument = $this->dokument($lauf, DocumentType::HAUSMEISTER_REINIGUNG_GARTEN, 'Unterlage 01');
        $this->felder($dokument, [
            'aussteller' => 'Gartenbau Beispiel',
            'belegnummer' => 'RE-1001',
            'belegdatum' => '2025-06-30',
            'leistungszeitraum_von' => '2025-01-01',
            'leistungszeitraum_bis' => '2025-06-30',
            'gesamtbetrag_brutto_cent' => 48000,
            'vorgeschlagene_kostenart' => 'Gartenpflege',
        ]);

        app(ReconcileBillingRun::class)->run($lauf);

        $position = CostItem::query()->where('billing_run_id', $lauf->getKey())->firstOrFail();

        self::assertSame(CostItemStatus::VORGESCHLAGEN, $position->getAttribute('status'));
        self::assertNull($position->getAttribute('confirmed_at'));
        self::assertSame(CostItemSource::KI_EXTRAKTION, $position->getAttribute('source'));
        self::assertSame(48000, $position->getAttribute('amount_cent'));
        self::assertSame('Gartenbau Beispiel', $position->getAttribute('supplier_name'));
        self::assertSame('RE-1001', $position->getAttribute('invoice_number'));
        self::assertSame($dokument->getKey(), $position->getAttribute('document_id'));
    }

    public function test_positionen_eines_belegs_werden_einzeln_vorgeschlagen(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $dokument = $this->dokument($lauf, DocumentType::RECHNUNG, 'Unterlage 02');
        $this->felder($dokument, [
            'aussteller' => 'Dienstleister Beispiel',
            'belegdatum' => '2025-12-01',
            'positionen[0].bezeichnung' => 'Gartenpflege Frühjahr',
            'positionen[0].betrag_brutto_cent' => 25000,
            'positionen[0].leistungszeitraum_von' => '2025-03-01',
            'positionen[0].leistungszeitraum_bis' => '2025-05-31',
            'positionen[1].bezeichnung' => 'Gebäudereinigung Treppenhaus',
            'positionen[1].betrag_brutto_cent' => 30000,
            'positionen[1].leistungszeitraum_von' => '2025-01-01',
            'positionen[1].leistungszeitraum_bis' => '2025-12-31',
        ]);

        app(ReconcileBillingRun::class)->run($lauf);

        self::assertSame(2, CostItem::query()->where('billing_run_id', $lauf->getKey())->count());
        self::assertSame(
            55000,
            (int) CostItem::query()->where('billing_run_id', $lauf->getKey())->sum('amount_cent')
        );
    }

    public function test_fehlender_betrag_erzeugt_pruefaufgabe_und_keine_position(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $dokument = $this->dokument($lauf, DocumentType::RECHNUNG, 'Unterlage 03');
        $this->felder($dokument, [
            'aussteller' => 'Dienstleister Beispiel',
            'belegdatum' => '2025-05-05',
            'gesamtbetrag_brutto_cent' => null,
        ]);

        app(ReconcileBillingRun::class)->run($lauf);

        self::assertSame(0, CostItem::query()->where('billing_run_id', $lauf->getKey())->count());

        $aufgabe = ValidationIssue::query()
            ->where('billing_run_id', $lauf->getKey())
            ->where('rule_code', RuleCode::MISSING_MANDATORY)
            ->firstOrFail();

        self::assertStringContainsString('Betrag', (string) $aufgabe->getAttribute('title'));
    }

    public function test_fehlendes_belegdatum_erzeugt_pruefaufgabe(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $dokument = $this->dokument($lauf, DocumentType::RECHNUNG, 'Unterlage 04');
        $this->felder($dokument, [
            'aussteller' => 'Dienstleister Beispiel',
            'belegdatum' => null,
            'gesamtbetrag_brutto_cent' => 12000,
            'vorgeschlagene_kostenart' => 'Gartenpflege',
            'leistungszeitraum_von' => '2025-01-01',
            'leistungszeitraum_bis' => '2025-12-31',
        ]);

        app(ReconcileBillingRun::class)->run($lauf);

        $aufgaben = ValidationIssue::query()
            ->where('billing_run_id', $lauf->getKey())
            ->where('rule_code', RuleCode::MISSING_MANDATORY)
            ->pluck('title')
            ->all();

        self::assertContains('Angabe fehlt: Belegdatum', $aufgaben);
        self::assertSame(1, CostItem::query()->where('billing_run_id', $lauf->getKey())->count());
    }

    public function test_fehlender_leistungszeitraum_erzeugt_pruefaufgabe_ohne_schaetzung(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $dokument = $this->dokument($lauf, DocumentType::RECHNUNG, 'Unterlage 05');
        $this->felder($dokument, [
            'belegdatum' => '2025-07-01',
            'gesamtbetrag_brutto_cent' => 9900,
            'vorgeschlagene_kostenart' => 'Gartenpflege',
            'leistungszeitraum_von' => null,
            'leistungszeitraum_bis' => null,
        ]);

        app(ReconcileBillingRun::class)->run($lauf);

        $position = CostItem::query()->where('billing_run_id', $lauf->getKey())->firstOrFail();

        self::assertNull($position->getAttribute('service_period_start'));
        self::assertNull($position->getAttribute('service_period_end'));

        $aufgaben = ValidationIssue::query()
            ->where('billing_run_id', $lauf->getKey())
            ->where('rule_code', RuleCode::MISSING_MANDATORY)
            ->pluck('title')
            ->all();

        self::assertContains('Angabe fehlt: Leistungszeitraum', $aufgaben);
    }

    public function test_lohnanteil_wird_nur_bei_ausgewiesenem_betrag_uebernommen(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $mit = $this->dokument($lauf, DocumentType::HAUSMEISTER_REINIGUNG_GARTEN, 'Unterlage 06');
        $this->felder($mit, [
            'belegdatum' => '2025-04-01',
            'gesamtbetrag_brutto_cent' => 60000,
            'vorgeschlagene_kostenart' => 'Gartenpflege',
            'lohnanteil_cent' => 40000,
            'leistungszeitraum_von' => '2025-01-01',
            'leistungszeitraum_bis' => '2025-12-31',
        ]);

        $ohne = $this->dokument($lauf, DocumentType::HAUSMEISTER_REINIGUNG_GARTEN, 'Unterlage 07');
        $this->felder($ohne, [
            'belegdatum' => '2025-04-02',
            'gesamtbetrag_brutto_cent' => 20000,
            'vorgeschlagene_kostenart' => 'Gebäudereinigung',
            'lohnanteil_cent' => null,
            'leistungszeitraum_von' => '2025-01-01',
            'leistungszeitraum_bis' => '2025-12-31',
        ]);

        app(ReconcileBillingRun::class)->run($lauf);

        $mitPosition = CostItem::query()->where('document_id', $mit->getKey())->firstOrFail();
        $ohnePosition = CostItem::query()->where('document_id', $ohne->getKey())->firstOrFail();

        self::assertSame(40000, $mitPosition->getAttribute('labor_share_cent'));
        self::assertNotSame(Paragraph35aType::NONE, $mitPosition->getAttribute('paragraph_35a_type'));
        self::assertNull($ohnePosition->getAttribute('labor_share_cent'));
        self::assertSame(Paragraph35aType::NONE, $ohnePosition->getAttribute('paragraph_35a_type'));
    }

    public function test_unklare_kostenart_bleibt_pruefpflichtig_und_ohne_kategorie(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $dokument = $this->dokument($lauf, DocumentType::RECHNUNG, 'Unterlage 08');
        $this->felder($dokument, [
            'belegdatum' => '2025-09-09',
            'gesamtbetrag_brutto_cent' => 15000,
            'vorgeschlagene_kostenart' => 'Diverse Leistungen',
            'leistungszeitraum_von' => '2025-01-01',
            'leistungszeitraum_bis' => '2025-12-31',
        ]);

        app(ReconcileBillingRun::class)->run($lauf);

        $position = CostItem::query()->where('billing_run_id', $lauf->getKey())->firstOrFail();

        self::assertNull($position->getAttribute('cost_category_id'));
        self::assertSame(ApportionmentStatus::PRUEFPFLICHTIG, $position->getAttribute('apportionment_status'));
    }

    public function test_nicht_umlagefaehige_kategorie_wird_ausgeschlossen_und_gewarnt(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $dokument = $this->dokument($lauf, DocumentType::RECHNUNG, 'Unterlage 09');
        $this->felder($dokument, [
            'belegdatum' => '2025-08-08',
            'gesamtbetrag_brutto_cent' => 89000,
            'vorgeschlagene_kostenart' => 'Reparatur der Hebeanlage',
            'leistungszeitraum_von' => '2025-01-01',
            'leistungszeitraum_bis' => '2025-12-31',
        ]);

        app(ReconcileBillingRun::class)->run($lauf);

        $position = CostItem::query()->where('billing_run_id', $lauf->getKey())->firstOrFail();

        self::assertSame(ApportionmentStatus::NICHT_UMLAGEFAEHIG, $position->getAttribute('apportionment_status'));
        self::assertTrue($position->getAttribute('excluded_from_apportionment'));

        self::assertTrue(
            ValidationIssue::query()
                ->where('billing_run_id', $lauf->getKey())
                ->where('rule_code', RuleCode::NOT_ALLOCABLE_CATEGORY)
                ->exists()
        );
    }

    public function test_leistungszeitraum_ausserhalb_des_abrechnungszeitraums_erzeugt_abgrenzungshinweis(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $dokument = $this->dokument($lauf, DocumentType::RECHNUNG, 'Unterlage 10');
        $this->felder($dokument, [
            'belegdatum' => '2024-11-01',
            'gesamtbetrag_brutto_cent' => 33000,
            'vorgeschlagene_kostenart' => 'Gartenpflege',
            'leistungszeitraum_von' => '2024-01-01',
            'leistungszeitraum_bis' => '2024-12-31',
        ]);

        app(ReconcileBillingRun::class)->run($lauf);

        self::assertTrue(
            ValidationIssue::query()
                ->where('billing_run_id', $lauf->getKey())
                ->where('rule_code', RuleCode::SERVICE_PERIOD_OUTSIDE)
                ->exists()
        );
    }

    public function test_erneuter_lauf_ersetzt_nur_unentschiedene_vorschlaege(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $dokument = $this->dokument($lauf, DocumentType::RECHNUNG, 'Unterlage 11');
        $this->felder($dokument, [
            'belegdatum' => '2025-03-03',
            'gesamtbetrag_brutto_cent' => 10000,
            'vorgeschlagene_kostenart' => 'Gartenpflege',
            'leistungszeitraum_von' => '2025-01-01',
            'leistungszeitraum_bis' => '2025-12-31',
        ]);

        app(ReconcileBillingRun::class)->run($lauf);

        $position = CostItem::query()->where('billing_run_id', $lauf->getKey())->firstOrFail();
        $position->forceFill(['status' => CostItemStatus::BESTAETIGT, 'confirmed_at' => now()])->save();

        app(ReconcileBillingRun::class)->run($lauf);

        self::assertSame(2, CostItem::query()->where('billing_run_id', $lauf->getKey())->count());
        self::assertSame(
            CostItemStatus::BESTAETIGT,
            CostItem::query()->whereKey($position->getKey())->firstOrFail()->getAttribute('status')
        );
    }

    public function test_gutschrift_wird_nicht_automatisch_verrechnet(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $dokument = $this->dokument($lauf, DocumentType::GUTSCHRIFT, 'Unterlage 12');
        $this->felder($dokument, [
            'belegart' => 'GUTSCHRIFT',
            'belegdatum' => '2025-10-10',
            'gesamtbetrag_brutto_cent' => 5000,
            'vorgeschlagene_kostenart' => 'Gartenpflege',
            'leistungszeitraum_von' => '2025-01-01',
            'leistungszeitraum_bis' => '2025-12-31',
        ]);

        app(ReconcileBillingRun::class)->run($lauf);

        $position = CostItem::query()->where('billing_run_id', $lauf->getKey())->firstOrFail();

        self::assertSame(5000, $position->getAttribute('amount_cent'));
        self::assertSame(ApportionmentStatus::PRUEFPFLICHTIG, $position->getAttribute('apportionment_status'));
    }

    public function test_mapper_und_writer_sind_getrennt_nutzbar(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $dokument = $this->dokument($lauf, DocumentType::ALLGEMEINSTROM, 'Unterlage 13');
        $this->felder($dokument, [
            'belegdatum' => '2025-02-02',
            'gesamtbetrag_brutto_cent' => 22000,
            'vorgeschlagene_kostenart' => 'Allgemeinstrom',
            'leistungszeitraum_von' => '2025-01-01',
            'leistungszeitraum_bis' => '2025-12-31',
        ]);

        $ergebnis = app(CostItemMapper::class)->map($lauf, $dokument);

        self::assertCount(1, $ergebnis->proposals);
        self::assertSame(22000, $ergebnis->totalProposedCent());
        self::assertSame(0, CostItem::query()->where('billing_run_id', $lauf->getKey())->count());

        app(CostItemProposalWriter::class)->write($lauf, $ergebnis);

        self::assertSame(1, CostItem::query()->where('billing_run_id', $lauf->getKey())->count());
    }
}
