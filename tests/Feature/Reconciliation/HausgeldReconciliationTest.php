<?php

declare(strict_types=1);

namespace Tests\Feature\Reconciliation;

use App\Application\Reconciliation\HausgeldReconciler;
use App\Application\Reconciliation\ReconcileBillingRun;
use App\Application\Reconciliation\RuleCode;
use App\Application\Reconciliation\Support\ExtractedFieldBag;
use App\Domain\Calculation\Weg\HausgeldPositionKind;
use App\Enums\ApportionmentStatus;
use App\Enums\CostItemStatus;
use App\Enums\DocumentType;
use App\Enums\ValidationSeverity;
use App\Models\BillingRun;
use App\Models\CostItem;
use App\Models\Document;
use App\Models\ValidationIssue;
use Tests\Feature\Review\ReviewTestCase;

/**
 * Uebernahme der WEG-Einzelabrechnung, Abschnitt 7.1, 7.2 und 7.5.
 */
final class HausgeldReconciliationTest extends ReviewTestCase
{
    public function test_umlagefaehige_anteile_der_einheit_werden_uebernommen(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $dokument = $this->wegAbrechnung($lauf, [
            'kostenarten[0].bezeichnung' => 'Gartenpflege',
            'kostenarten[0].kategorie' => 'BETRIEBSKOSTEN',
            'kostenarten[0].gesamtkosten_cent' => 240000,
            'kostenarten[0].anteil_einheit_cent' => 24000,
            'kostenarten[1].bezeichnung' => 'Gebäudereinigung',
            'kostenarten[1].kategorie' => 'BETRIEBSKOSTEN',
            'kostenarten[1].anteil_einheit_cent' => 18000,
        ]);

        app(ReconcileBillingRun::class)->run($lauf);

        $positionen = CostItem::query()->where('document_id', $dokument->getKey())->get();

        self::assertCount(2, $positionen);
        self::assertSame(42000, (int) $positionen->sum('amount_cent'));

        foreach ($positionen as $position) {
            self::assertSame(CostItemStatus::VORGESCHLAGEN, $position->getAttribute('status'));
        }
    }

    /**
     * Jede einzelne Ausschlussposition aus Abschnitt 7.2.
     */
    public function test_hausgeldvorauszahlungen_werden_ausgeschlossen(): void
    {
        $this->pruefeAusschluss('hausgeldvorauszahlungen_cent', 360000, HausgeldPositionKind::HOUSE_MONEY_PREPAYMENT);
    }

    public function test_abrechnungsspitze_wird_ausgeschlossen(): void
    {
        $this->pruefeAusschluss('abrechnungsspitze_cent', 42000, HausgeldPositionKind::SETTLEMENT_BALANCE);
    }

    public function test_ruecklagenzufuehrung_wird_ausgeschlossen(): void
    {
        $this->pruefeAusschluss('ruecklagenzufuehrung_cent', 60000, HausgeldPositionKind::RESERVE_CONTRIBUTION);
    }

    public function test_ruecklagenentnahme_wird_ausgeschlossen(): void
    {
        $this->pruefeAusschluss('ruecklagenentnahme_cent', 15000, HausgeldPositionKind::RESERVE_WITHDRAWAL);
    }

    public function test_verwalterkosten_werden_ausgeschlossen(): void
    {
        $this->pruefeAusschluss('verwalterverguetung_cent', 30000, HausgeldPositionKind::ADMINISTRATION_COST);
    }

    public function test_bank_und_finanzierungskosten_werden_ausgeschlossen(): void
    {
        $this->pruefeAusschluss('bank_finanzierungskosten_cent', 4500, HausgeldPositionKind::BANK_AND_FINANCING_COST);
    }

    public function test_instandhaltung_und_reparaturen_werden_ausgeschlossen(): void
    {
        $this->pruefeAusschluss('instandhaltung_reparatur_cent', 125000, HausgeldPositionKind::MAINTENANCE_AND_REPAIR);
    }

    public function test_rechts_und_prozesskosten_werden_ausgeschlossen(): void
    {
        $this->pruefeAusschluss('rechts_prozesskosten_cent', 89000, HausgeldPositionKind::LEGAL_COST);
    }

    public function test_nicht_bezeichnete_sammelposition_wird_ausgeschlossen(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $dokument = $this->wegAbrechnung($lauf, [
            'kostenarten[0].bezeichnung' => 'Gartenpflege',
            'kostenarten[0].kategorie' => 'BETRIEBSKOSTEN',
            'kostenarten[0].anteil_einheit_cent' => 24000,
            'kostenarten[1].bezeichnung' => 'Sonstige Kosten',
            'kostenarten[1].kategorie' => 'SAMMELPOSITION_UNBEZEICHNET',
            'kostenarten[1].anteil_einheit_cent' => 50000,
        ]);

        $ergebnis = app(ReconcileBillingRun::class)->run($lauf);

        $arten = array_map(
            static fn ($zeile): string => $zeile->kind,
            $ergebnis->excludedPositions
        );

        self::assertContains(HausgeldPositionKind::UNLABELLED_COLLECTIVE_POSITION->value, $arten);
        self::assertSame(24000, (int) CostItem::query()->where('document_id', $dokument->getKey())->sum('amount_cent'));
    }

    public function test_ausgeschlossene_positionen_werden_getrennt_ausgewiesen(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->wegAbrechnung($lauf, [
            'kostenarten[0].bezeichnung' => 'Gartenpflege',
            'kostenarten[0].kategorie' => 'BETRIEBSKOSTEN',
            'kostenarten[0].anteil_einheit_cent' => 24000,
            'verwalterverguetung_cent' => 30000,
            'ruecklagenzufuehrung_cent' => 60000,
        ]);

        $ergebnis = app(ReconcileBillingRun::class)->run($lauf);

        self::assertCount(2, $ergebnis->excludedPositions);
        self::assertSame(90000, array_sum(array_map(
            static fn ($zeile): int => $zeile->amountCent,
            $ergebnis->excludedPositions
        )));

        self::assertTrue(
            ValidationIssue::query()
                ->where('billing_run_id', $lauf->getKey())
                ->where('rule_code', RuleCode::WEG_POSITION_EXCLUDED)
                ->exists()
        );
    }

    public function test_verwalterkennzeichnung_umlagefaehig_fuehrt_nicht_zur_freigabe(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $dokument = $this->wegAbrechnung($lauf, [
            'kostenarten[0].bezeichnung' => 'Instandsetzung Dach',
            'kostenarten[0].kategorie' => 'INSTANDHALTUNG_INSTANDSETZUNG',
            'kostenarten[0].anteil_einheit_cent' => 150000,
            'kostenarten[0].verwalter_kennzeichnung_umlagefaehig' => true,
        ]);

        app(ReconcileBillingRun::class)->run($lauf);

        self::assertSame(0, CostItem::query()->where('document_id', $dokument->getKey())->count());

        $aufgabe = ValidationIssue::query()
            ->where('billing_run_id', $lauf->getKey())
            ->where('rule_code', RuleCode::WEG_MANAGER_FLAG_NOT_A_RELEASE)
            ->firstOrFail();

        self::assertSame(ValidationSeverity::WARNUNG, $aufgabe->getAttribute('severity'));
        self::assertStringContainsString('keine Freigabe', (string) $aufgabe->getAttribute('description'));
    }

    public function test_verwalterkennzeichnung_bestaetigt_keine_position_automatisch(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $dokument = $this->wegAbrechnung($lauf, [
            'kostenarten[0].bezeichnung' => 'Gartenpflege',
            'kostenarten[0].kategorie' => 'BETRIEBSKOSTEN',
            'kostenarten[0].anteil_einheit_cent' => 24000,
            'kostenarten[0].verwalter_kennzeichnung_umlagefaehig' => true,
        ]);

        app(ReconcileBillingRun::class)->run($lauf);

        $position = CostItem::query()->where('document_id', $dokument->getKey())->firstOrFail();

        self::assertSame(CostItemStatus::VORGESCHLAGEN, $position->getAttribute('status'));
        self::assertNull($position->getAttribute('confirmed_at'));
    }

    public function test_nur_hausgeldbetrag_ohne_aufschluesselung_fordert_einzelabrechnung(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->wegAbrechnung($lauf, [
            'hausgeldvorauszahlungen_cent' => 360000,
            'abrechnungsspitze_cent' => 42000,
            'kostenaufschluesselung_vorhanden' => false,
        ]);

        app(ReconcileBillingRun::class)->run($lauf);

        self::assertSame(0, CostItem::query()->where('billing_run_id', $lauf->getKey())->count());

        $aufgabe = ValidationIssue::query()
            ->where('billing_run_id', $lauf->getKey())
            ->where('rule_code', RuleCode::INSUFFICIENT_DOCUMENTS)
            ->firstOrFail();

        self::assertTrue($aufgabe->getAttribute('blocks_finalization'));
        self::assertSame(ValidationSeverity::BLOCKER, $aufgabe->getAttribute('severity'));
        self::assertStringContainsString('Einzelabrechnung', (string) $aufgabe->getAttribute('description'));
    }

    public function test_kostenart_ohne_erkennbare_einordnung_wird_nicht_geraten(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $dokument = $this->wegAbrechnung($lauf, [
            'kostenarten[0].bezeichnung' => 'Position 4711',
            'kostenarten[0].kategorie' => null,
            'kostenarten[0].anteil_einheit_cent' => 7000,
            'kostenarten[1].bezeichnung' => 'Gartenpflege',
            'kostenarten[1].kategorie' => 'BETRIEBSKOSTEN',
            'kostenarten[1].anteil_einheit_cent' => 24000,
        ]);

        app(ReconcileBillingRun::class)->run($lauf);

        self::assertSame(1, CostItem::query()->where('document_id', $dokument->getKey())->count());
    }

    public function test_bag_liest_listenindizes_der_kostenarten(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $dokument = $this->wegAbrechnung($lauf, [
            'kostenarten[0].bezeichnung' => 'Gartenpflege',
            'kostenarten[0].kategorie' => 'BETRIEBSKOSTEN',
            'kostenarten[0].anteil_einheit_cent' => 24000,
            'kostenarten[2].bezeichnung' => 'Allgemeinstrom',
            'kostenarten[2].kategorie' => 'BETRIEBSKOSTEN',
            'kostenarten[2].anteil_einheit_cent' => 9000,
        ]);

        $bag = ExtractedFieldBag::forDocument($dokument);

        self::assertSame([0, 2], $bag->listIndexes('kostenarten'));

        $statement = app(HausgeldReconciler::class)->buildStatement($lauf, $dokument, $bag);

        self::assertTrue($statement->hasCostBreakdown());
    }

    /**
     * @param  array<string, mixed>  $felder
     */
    private function wegAbrechnung(BillingRun $lauf, array $felder): Document
    {
        $dokument = $this->dokument($lauf, DocumentType::WEG_HAUSGELDABRECHNUNG_EINZEL, 'Unterlage WEG');

        $this->felder($dokument, array_merge([
            'weg_bezeichnung' => 'WEG Beispielstraße 1',
            'einheitsbezeichnung' => 'Wohnung 3',
            'verwalter' => 'Verwaltung Beispiel',
            'abrechnungszeitraum_von' => '2025-01-01',
            'abrechnungszeitraum_bis' => '2025-12-31',
        ], $felder));

        return $dokument;
    }

    private function pruefeAusschluss(string $pfad, int $betrag, HausgeldPositionKind $art): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $dokument = $this->wegAbrechnung($lauf, [
            'kostenarten[0].bezeichnung' => 'Gartenpflege',
            'kostenarten[0].kategorie' => 'BETRIEBSKOSTEN',
            'kostenarten[0].anteil_einheit_cent' => 24000,
            $pfad => $betrag,
        ]);

        $ergebnis = app(ReconcileBillingRun::class)->run($lauf);

        $zeilen = array_values(array_filter(
            $ergebnis->excludedPositions,
            static fn ($zeile): bool => $zeile->kind === $art->value
        ));

        self::assertCount(1, $zeilen, sprintf('Die Position %s wird nicht getrennt ausgewiesen.', $pfad));
        self::assertSame($betrag, $zeilen[0]->amountCent);
        self::assertNotSame('', $zeilen[0]->reason);

        // Der Betrag darf in keiner Kostenposition auftauchen.
        self::assertSame(
            24000,
            (int) CostItem::query()->where('document_id', $dokument->getKey())->sum('amount_cent')
        );

        self::assertSame(
            ApportionmentStatus::UMLAGEFAEHIG,
            CostItem::query()->where('document_id', $dokument->getKey())->firstOrFail()->getAttribute('apportionment_status')
        );
    }
}
