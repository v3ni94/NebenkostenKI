<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Weg;

use App\Domain\Calculation\AllocabilityStatus;
use App\Domain\Calculation\Result\CheckCode;
use App\Domain\Calculation\Result\CheckSeverity;
use App\Domain\Calculation\TaxBenefitCategory;
use App\Domain\Calculation\Weg\HausgeldCostExtractor;
use App\Domain\Calculation\Weg\HausgeldPositionInput;
use App\Domain\Calculation\Weg\HausgeldPositionKind;
use App\Domain\Calculation\Weg\HausgeldStatementInput;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Hausgeldabrechnung: Übernahme der umlagefähigen Anteile der Einheit und
 * verbindlicher Ausschluss der Werte nach Abschnitt 7.2 des Pflichtenhefts.
 */
final class HausgeldCostExtractorTest extends TestCase
{
    private HausgeldCostExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new HausgeldCostExtractor;
    }

    #[Test]
    public function umlagefaehige_anteile_der_einheit_werden_uebernommen(): void
    {
        // 412,80 + 186,40 + 243,15 = 842,35 EUR
        $result = $this->extractor->extract($this->statement([
            $this->position('p-1', 'Wasser und Abwasser', 'WASSER', '412.80'),
            $this->position('p-2', 'Müllbeseitigung', 'MUELL', '186.40'),
            $this->position('p-3', 'Gebäudeversicherung', 'VERSICHERUNG', '243.15'),
        ]), 'einheit');

        $this->assertCount(3, $result->acceptedCostItems);
        $this->assertSame(84235, $result->acceptedTotal->cents);
        $this->assertSame(0, $result->excludedTotal->cents);
        $this->assertTrue($result->sufficientBreakdown);
        $this->assertFalse($result->blocksFinalization());
        $this->assertTrue($result->containsCategory('WASSER'));
        $this->assertSame('einheit', $result->acceptedCostItems[0]->allocationKeyRef);
        $this->assertSame(
            AllocabilityStatus::ALLOCABLE,
            $result->acceptedCostItems[0]->allocabilityStatus
        );
    }

    #[Test]
    public function ruecklage_verwalterkosten_und_reparatur_werden_ausgeschlossen(): void
    {
        // Ausgeschlossen: 720,00 + 288,00 + 1.450,00 + 24,00 + 150,00 = 2.632,00 EUR
        $result = $this->extractor->extract($this->statement([
            $this->position('p-1', 'Wasser und Abwasser', 'WASSER', '412.80'),
            $this->position('p-2', 'Zuführung Erhaltungsrücklage', 'RUECKLAGE', '720.00', HausgeldPositionKind::RESERVE_CONTRIBUTION),
            $this->position('p-3', 'Verwaltervergütung', 'VERWALTUNG', '288.00', HausgeldPositionKind::ADMINISTRATION_COST),
            $this->position('p-4', 'Instandsetzung Dach', 'INSTANDHALTUNG', '1450.00', HausgeldPositionKind::MAINTENANCE_AND_REPAIR),
            $this->position('p-5', 'Bankgebühren', 'BANK', '24.00', HausgeldPositionKind::BANK_AND_FINANCING_COST),
            $this->position('p-6', 'Rechtsberatung', 'RECHT', '150.00', HausgeldPositionKind::LEGAL_COST),
        ]), 'einheit');

        $this->assertCount(1, $result->acceptedCostItems);
        $this->assertSame(41280, $result->acceptedTotal->cents);
        $this->assertSame(263200, $result->excludedTotal->cents);
        $this->assertCount(5, $result->excludedPositions);
        $this->assertSame(['p-2', 'p-3', 'p-4', 'p-5', 'p-6'], $result->excludedPositionKeys());
        $this->assertStringContainsString(
            'Erhaltungsrücklage',
            $result->excludedPositions[0]->reason
        );
    }

    #[Test]
    public function hausgeldvorauszahlung_und_abrechnungsspitze_werden_nicht_uebernommen(): void
    {
        $result = $this->extractor->extract($this->statement([
            $this->position('p-1', 'Wasser und Abwasser', 'WASSER', '412.80'),
            $this->position('p-2', 'Hausgeldvorauszahlungen', 'HAUSGELD', '3600.00', HausgeldPositionKind::HOUSE_MONEY_PREPAYMENT),
            $this->position('p-3', 'Abrechnungsspitze', 'SPITZE', '212.45', HausgeldPositionKind::SETTLEMENT_BALANCE),
        ]), 'einheit');

        $this->assertCount(1, $result->acceptedCostItems);
        $this->assertSame(381245, $result->excludedTotal->cents);
        $this->assertCount(2, $result->excludedPositions);
    }

    #[Test]
    public function unbezeichnete_sammelposition_erzeugt_eine_warnung(): void
    {
        $result = $this->extractor->extract($this->statement([
            $this->position('p-1', 'Wasser und Abwasser', 'WASSER', '412.80'),
            $this->position('p-2', 'Sonstige Kosten', 'SAMMEL', '480.00', HausgeldPositionKind::UNLABELLED_COLLECTIVE_POSITION),
        ]), 'einheit');

        $warnings = array_values(array_filter(
            $result->findings,
            static fn ($finding): bool => $finding->code === CheckCode::WEG_UNLABELLED_POSITION
        ));

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('nicht aufgeschlüsselt', $warnings[0]->message);
        $this->assertCount(1, $result->acceptedCostItems);
    }

    #[Test]
    public function kennzeichnung_des_verwalters_ist_keine_rechtsfreigabe(): void
    {
        $result = $this->extractor->extract($this->statement([
            $this->position('p-1', 'Wasser und Abwasser', 'WASSER', '412.80'),
            new HausgeldPositionInput(
                'p-2',
                'Instandsetzung Treppenhaus',
                'INSTANDHALTUNG',
                Money::fromEuros('890.00'),
                HausgeldPositionKind::MAINTENANCE_AND_REPAIR,
                null,
                true
            ),
        ]), 'einheit');

        $this->assertCount(1, $result->acceptedCostItems);
        $this->assertSame(89000, $result->excludedTotal->cents);

        $messages = array_map(static fn ($finding): string => $finding->message, $result->findings);
        $this->assertStringContainsString('keine Rechtsfreigabe', implode(' ', $messages));
    }

    #[Test]
    public function fehlende_kostenaufschluesselung_blockiert_die_finalisierung(): void
    {
        $result = $this->extractor->extract($this->statement([
            $this->position('p-1', 'Hausgeldvorauszahlungen', 'HAUSGELD', '3600.00', HausgeldPositionKind::HOUSE_MONEY_PREPAYMENT),
            $this->position('p-2', 'Abrechnungsspitze', 'SPITZE', '212.45', HausgeldPositionKind::SETTLEMENT_BALANCE),
        ]), 'einheit');

        $this->assertFalse($result->sufficientBreakdown);
        $this->assertTrue($result->blocksFinalization());
        $this->assertSame(CheckCode::WEG_INSUFFICIENT_BREAKDOWN, $result->findings[0]->code);
        $this->assertStringContainsString('Einzelabrechnung', $result->findings[0]->message);
        $this->assertSame([], $result->acceptedCostItems);
    }

    #[Test]
    public function heizkosten_werden_bei_vorliegender_externer_abrechnung_nicht_doppelt_angesetzt(): void
    {
        $result = $this->extractor->extract(
            $this->statement([
                $this->position('p-1', 'Wasser und Abwasser', 'WASSER', '412.80'),
                $this->position('p-2', 'Heiz- und Warmwasserkosten', 'HEIZUNG', '1150.00', HausgeldPositionKind::HEATING_COST),
            ]),
            'einheit',
            true
        );

        $this->assertCount(1, $result->acceptedCostItems);
        $this->assertFalse($result->containsCategory('HEIZUNG'));
        $this->assertSame(115000, $result->excludedTotal->cents);
        $this->assertStringContainsString('Vergleichssumme', $result->excludedPositions[0]->reason);
    }

    #[Test]
    public function ohne_externe_abrechnung_bleiben_die_weg_heizkosten_kostenquelle(): void
    {
        $result = $this->extractor->extract(
            $this->statement([
                $this->position('p-1', 'Heiz- und Warmwasserkosten', 'HEIZUNG', '1150.00', HausgeldPositionKind::HEATING_COST),
            ]),
            'einheit',
            false
        );

        $this->assertCount(1, $result->acceptedCostItems);
        $this->assertTrue($result->containsCategory('HEIZUNG'));
        $this->assertSame(115000, $result->acceptedTotal->cents);
    }

    #[Test]
    public function pruefsumme_gegen_den_gesamtanteil_der_einheit_wird_gebildet(): void
    {
        $statement = new HausgeldStatementInput(
            'W-12',
            DatePeriodRange::calendarYear(2025),
            [
                $this->position('p-1', 'Wasser und Abwasser', 'WASSER', '412.80'),
                $this->position('p-2', 'Verwaltervergütung', 'VERWALTUNG', '288.00', HausgeldPositionKind::ADMINISTRATION_COST),
            ],
            Money::fromEuros('700.80'),
        );

        $result = $this->extractor->extract($statement, 'einheit');

        $checksums = array_values(array_filter(
            $result->findings,
            static fn ($finding): bool => $finding->code === CheckCode::WEG_UNIT_SHARE_CHECKSUM
        ));

        $this->assertCount(1, $checksums);
        $this->assertSame(CheckSeverity::PASSED, $checksums[0]->severity);
        $this->assertStringContainsString('ergeben 700,80 EUR', $checksums[0]->message);
    }

    #[Test]
    public function abweichende_pruefsumme_erzeugt_eine_warnung(): void
    {
        // Einzelpositionen 700,80 EUR gegenüber ausgewiesenem Gesamtanteil
        // 725,00 EUR: Abweichung -24,20 EUR.
        $statement = new HausgeldStatementInput(
            'W-12',
            DatePeriodRange::calendarYear(2025),
            [
                $this->position('p-1', 'Wasser und Abwasser', 'WASSER', '412.80'),
                $this->position('p-2', 'Verwaltervergütung', 'VERWALTUNG', '288.00', HausgeldPositionKind::ADMINISTRATION_COST),
            ],
            Money::fromEuros('725.00'),
        );

        $result = $this->extractor->extract($statement, 'einheit');

        $checksums = array_values(array_filter(
            $result->findings,
            static fn ($finding): bool => $finding->code === CheckCode::WEG_UNIT_SHARE_CHECKSUM
        ));

        $this->assertCount(1, $checksums);
        $this->assertSame(CheckSeverity::WARNING, $checksums[0]->severity);
        $this->assertStringContainsString('Abweichung: -24,20 EUR', $checksums[0]->message);
        $this->assertFalse($result->blocksFinalization());
    }

    #[Test]
    public function paragraph_35a_angaben_werden_aus_der_hausgeldabrechnung_uebernommen(): void
    {
        $result = $this->extractor->extract($this->statement([
            new HausgeldPositionInput(
                'p-1',
                'Gartenpflege',
                'GARTENPFLEGE',
                Money::fromEuros('310.00'),
                HausgeldPositionKind::OPERATING_COST,
                null,
                true,
                TaxBenefitCategory::HOUSEHOLD_SERVICE,
                Money::fromEuros('210.00')
            ),
            new HausgeldPositionInput(
                'p-2',
                'Hauswart',
                'HAUSWART',
                Money::fromEuros('480.00'),
                HausgeldPositionKind::OPERATING_COST,
                null,
                true,
                TaxBenefitCategory::HOUSEHOLD_SERVICE,
                null,
                false
            ),
        ]), 'einheit');

        $garten = $result->acceptedItem('weg-p-1');
        $hauswart = $result->acceptedItem('weg-p-2');

        $this->assertNotNull($garten);
        $this->assertNotNull($hauswart);
        $this->assertSame(21000, $garten->benefitedLaborShare()?->cents);
        $this->assertNull($hauswart->benefitedLaborShare());
        $this->assertTrue($hauswart->hasUndisclosedLaborShare());
    }

    /**
     * @param  list<HausgeldPositionInput>  $positions
     */
    private function statement(array $positions, ?Money $totalUnitShare = null): HausgeldStatementInput
    {
        return new HausgeldStatementInput(
            'W-12',
            DatePeriodRange::calendarYear(2025),
            $positions,
            $totalUnitShare,
            null,
            null,
            'WEG Rheinpromenade 13'
        );
    }

    private function position(
        string $key,
        string $label,
        string $categoryKey,
        string $amount,
        HausgeldPositionKind $kind = HausgeldPositionKind::OPERATING_COST,
    ): HausgeldPositionInput {
        return new HausgeldPositionInput(
            $key,
            $label,
            $categoryKey,
            Money::fromEuros($amount),
            $kind
        );
    }
}
