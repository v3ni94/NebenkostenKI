<?php

declare(strict_types=1);

namespace Tests\Feature\Reconciliation;

use App\Application\Reconciliation\PositionDuplicateScanner;
use App\Application\Reconciliation\ReconcileBillingRun;
use App\Application\Reconciliation\RuleCode;
use App\Enums\DocumentRelationType;
use App\Enums\DocumentType;
use App\Models\BillingRun;
use App\Models\CostItem;
use App\Models\Document;
use App\Models\DocumentRelation;
use App\Models\ValidationIssue;
use Tests\Feature\Review\ReviewTestCase;

/**
 * Dublettenerkennung auf Positionsebene (Abschnitt 12.5).
 */
final class PositionDuplicateTest extends ReviewTestCase
{
    public function test_gleiche_rechnungsnummer_wird_als_dublette_gefuehrt(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->beleg($lauf, 'Unterlage A', 'RE-500', 48000, '2025-06-30', 'fingerprint-a');
        $this->beleg($lauf, 'Unterlage B', 'RE-500', 48000, '2025-07-01', 'fingerprint-b');

        $ergebnis = app(ReconcileBillingRun::class)->run($lauf);

        self::assertCount(1, $ergebnis->duplicates);
        self::assertStringContainsString('Rechnungsnummer', $ergebnis->duplicates[0]->reason);

        self::assertTrue(
            ValidationIssue::query()
                ->where('billing_run_id', $lauf->getKey())
                ->where('rule_code', RuleCode::DUPLICATE_POSITION)
                ->exists()
        );

        self::assertTrue(
            DocumentRelation::query()
                ->where('billing_run_id', $lauf->getKey())
                ->where('relation_type', DocumentRelationType::DUBLETTE->value)
                ->exists()
        );
    }

    public function test_gleicher_betrag_mit_gleichem_datum_wird_erkannt(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->beleg($lauf, 'Unterlage A', 'RE-1', 48000, '2025-06-30', 'fingerprint-a');
        $this->beleg($lauf, 'Unterlage B', 'RE-2', 48000, '2025-06-30', 'fingerprint-b');

        $ergebnis = app(ReconcileBillingRun::class)->run($lauf);

        self::assertCount(1, $ergebnis->duplicates);
        self::assertStringContainsString('gleichem Belegdatum', $ergebnis->duplicates[0]->reason);
    }

    public function test_gleicher_fingerabdruck_wird_erkannt(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->beleg($lauf, 'Unterlage A', 'RE-1', 48000, '2025-06-30', 'derselbe-fingerabdruck');
        $this->beleg($lauf, 'Unterlage B', 'RE-2', 12000, '2025-08-30', 'derselbe-fingerabdruck');

        $ergebnis = app(ReconcileBillingRun::class)->run($lauf);

        self::assertCount(1, $ergebnis->duplicates);
        self::assertStringContainsString('Fingerabdruck', $ergebnis->duplicates[0]->reason);
    }

    public function test_dublette_wird_nicht_still_addiert_sondern_gekennzeichnet(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->beleg($lauf, 'Unterlage A', 'RE-500', 48000, '2025-06-30', 'fingerprint-a');
        $this->beleg($lauf, 'Unterlage B', 'RE-500', 48000, '2025-07-01', 'fingerprint-b');

        app(ReconcileBillingRun::class)->run($lauf);

        $gekennzeichnet = CostItem::query()
            ->where('billing_run_id', $lauf->getKey())
            ->whereNotNull('duplicate_of_cost_item_id')
            ->count();

        self::assertSame(1, $gekennzeichnet);
        self::assertSame(2, CostItem::query()->where('billing_run_id', $lauf->getKey())->count());
    }

    public function test_rechnung_und_gutschrift_werden_als_paar_erkannt(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->beleg($lauf, 'Unterlage Rechnung', 'RE-900', 48000, '2025-06-30', 'fingerprint-r');

        $gutschrift = $this->dokument($lauf, DocumentType::GUTSCHRIFT, 'Unterlage Gutschrift');
        $this->felder($gutschrift, [
            'belegart' => 'GUTSCHRIFT',
            'aussteller' => 'Gartenbau Beispiel',
            'belegnummer' => 'GS-901',
            'bezug_auf_belegnummer' => 'RE-900',
            'belegdatum' => '2025-08-15',
            'gesamtbetrag_brutto_cent' => 8000,
            'vorgeschlagene_kostenart' => 'Gartenpflege',
            'leistungszeitraum_von' => '2025-01-01',
            'leistungszeitraum_bis' => '2025-12-31',
        ]);

        $ergebnis = app(ReconcileBillingRun::class)->run($lauf);

        self::assertCount(1, $ergebnis->duplicates);
        self::assertTrue($ergebnis->duplicates[0]->isCreditNotePair);

        self::assertTrue(
            ValidationIssue::query()
                ->where('billing_run_id', $lauf->getKey())
                ->where('rule_code', RuleCode::CREDIT_NOTE_PAIR)
                ->exists()
        );

        self::assertTrue(
            DocumentRelation::query()
                ->where('billing_run_id', $lauf->getKey())
                ->where('relation_type', DocumentRelationType::GUTSCHRIFT_ZU_RECHNUNG->value)
                ->exists()
        );

        // Eine Gutschrift ist keine Dublette und wird nicht als solche markiert.
        self::assertSame(
            0,
            CostItem::query()->where('billing_run_id', $lauf->getKey())->whereNotNull('duplicate_of_cost_item_id')->count()
        );
    }

    public function test_mehrere_positionen_eines_belegs_sind_keine_dublette(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $dokument = $this->dokument($lauf, DocumentType::RECHNUNG, 'Unterlage Sammelbeleg');
        $this->felder($dokument, [
            'aussteller' => 'Dienstleister Beispiel',
            'belegnummer' => 'RE-777',
            'belegdatum' => '2025-05-05',
            'positionen[0].bezeichnung' => 'Gartenpflege',
            'positionen[0].betrag_brutto_cent' => 20000,
            'positionen[1].bezeichnung' => 'Gebäudereinigung',
            'positionen[1].betrag_brutto_cent' => 20000,
        ]);

        $ergebnis = app(ReconcileBillingRun::class)->run($lauf);

        self::assertSame([], $ergebnis->duplicates);
    }

    public function test_scanner_ist_ohne_reconciliation_nutzbar(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        CostItem::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'billing_run_id' => $lauf->getKey(),
            'document_id' => null,
            'description' => 'Position A',
            'supplier_name' => 'Lieferant',
            'invoice_number' => 'RE-42',
            'amount_cent' => 10000,
            'document_date' => '2025-01-01',
        ]);

        CostItem::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'billing_run_id' => $lauf->getKey(),
            'document_id' => null,
            'description' => 'Position B',
            'supplier_name' => 'Lieferant',
            'invoice_number' => 'RE-42',
            'amount_cent' => 10000,
            'document_date' => '2025-01-01',
        ]);

        $treffer = app(PositionDuplicateScanner::class)->scan($lauf);

        self::assertCount(1, $treffer);
    }

    private function beleg(
        BillingRun $lauf,
        string $bezeichnung,
        string $belegnummer,
        int $betrag,
        string $datum,
        string $fingerabdruck,
    ): Document {
        $dokument = $this->dokument($lauf, DocumentType::HAUSMEISTER_REINIGUNG_GARTEN, $bezeichnung, [
            'fingerprint_hmac' => $fingerabdruck,
        ]);

        $this->felder($dokument, [
            'aussteller' => 'Gartenbau Beispiel',
            'belegnummer' => $belegnummer,
            'belegdatum' => $datum,
            'gesamtbetrag_brutto_cent' => $betrag,
            'vorgeschlagene_kostenart' => 'Gartenpflege',
            'leistungszeitraum_von' => '2025-01-01',
            'leistungszeitraum_bis' => '2025-12-31',
        ]);

        return $dokument;
    }
}
