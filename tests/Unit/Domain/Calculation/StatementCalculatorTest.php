<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Calculation;

use App\Domain\Allocation\AllocationKey;
use App\Domain\Allocation\ConsumptionKey;
use App\Domain\Allocation\CoOwnershipShareKey;
use App\Domain\Allocation\DirectAssignmentKey;
use App\Domain\Allocation\InvalidAllocationKeyException;
use App\Domain\Allocation\LivingAreaKey;
use App\Domain\Allocation\PersonDaysKey;
use App\Domain\Allocation\PersonDaysSegment;
use App\Domain\Allocation\UnitCountKey;
use App\Domain\Calculation\AllocabilityStatus;
use App\Domain\Calculation\CalculationInputException;
use App\Domain\Calculation\Dto\CostItemInput;
use App\Domain\Calculation\Dto\OccupancyInput;
use App\Domain\Calculation\Dto\PrepaymentInput;
use App\Domain\Calculation\Dto\StatementCalculationInput;
use App\Domain\Calculation\Dto\UnitInput;
use App\Domain\Calculation\OccupancyKind;
use App\Domain\Calculation\OverlappingOccupancyException;
use App\Domain\Calculation\Result\CalculationRunResult;
use App\Domain\Calculation\Result\CheckCode;
use App\Domain\Calculation\Result\CheckSeverity;
use App\Domain\Calculation\Result\UnitStatementResult;
use App\Domain\Calculation\StatementCalculator;
use App\Domain\Calculation\TaxBenefitCategory;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Deterministische Berechnungsengine: Zeitanteile, Verteilerschlüssel,
 * Rundung, Umlagefähigkeit, Vorauszahlungen und § 35a EStG.
 *
 * Alle Erwartungswerte sind im jeweiligen Test mit dem Rechenweg
 * dokumentiert und von Hand geprüft.
 */
final class StatementCalculatorTest extends TestCase
{
    private StatementCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new StatementCalculator;
    }

    #[Test]
    public function volljahresmietverhaeltnis_erhaelt_den_vollen_anteil_der_einheit(): void
    {
        // Wohnfläche 72,50 von 310,00 m², Kosten 3.100,00 EUR
        // → 3.100,00 × 72,50 / 310,00 = 725,00 EUR, Zeitfaktor 365/365.
        $result = $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [
                $this->unit('W-1', '72.50'),
                $this->unit('W-2', '95.00'),
                $this->unit('W-3', '142.50'),
            ],
            [
                $this->tenancy('mv-1', 'W-1', '2025-01-01', '2025-12-31'),
                $this->tenancy('mv-2', 'W-2', '2025-01-01', '2025-12-31'),
                $this->tenancy('mv-3', 'W-3', '2025-01-01', '2025-12-31'),
            ],
            [$this->cost('k-1', 'WASSER', 'Wasserversorgung', '3100.00', 'flaeche')],
            ['flaeche' => new LivingAreaKey(['W-1' => '72.50', 'W-2' => '95.00', 'W-3' => '142.50'])]
        );

        $statement = $result->statement('mv-1');
        $this->assertNotNull($statement);
        $this->assertTrue($statement->allocableTotal->equals(Money::fromEuros('725.00')));
        $this->assertSame(365, $statement->usageDays());
        $this->assertSame('365 von 365 Tagen', $statement->lines[0]->timeFactor->explanation());
        $this->assertSame(
            'Wohnfläche 72,50 m² von 310,00 m²',
            $statement->lines[0]->allocationExplanation
        );
        $this->assertTrue($result->ownerOverview->isBalanced());
    }

    #[Test]
    public function mieterwechsel_zum_30_juni_wird_taggenau_geteilt(): void
    {
        // Einheit 62,50 von 100,00 m², Kosten 1.000,00 EUR → Einheitenanteil
        // 625,00 EUR. Aufteilung 181/365 und 184/365:
        // 62.500 × 181 / 365 = 30.993,1507 → 30.993 Cent
        // 62.500 × 184 / 365 = 31.506,8493 → 31.507 Cent (größter Rest)
        $result = $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-1', '62.50'), $this->unit('W-2', '37.50')],
            [
                $this->tenancy('mv-1', 'W-1', '2025-01-01', '2025-06-30'),
                $this->tenancy('mv-2', 'W-1', '2025-07-01', '2025-12-31'),
                $this->tenancy('mv-3', 'W-2', '2025-01-01', '2025-12-31'),
            ],
            [$this->cost('k-1', 'GRUNDSTEUER', 'Grundsteuer', '1000.00', 'flaeche')],
            ['flaeche' => new LivingAreaKey(['W-1' => '62.50', 'W-2' => '37.50'])]
        );

        $this->assertSame(30993, $this->statement($result, 'mv-1')->allocableTotal->cents);
        $this->assertSame(31507, $this->statement($result, 'mv-2')->allocableTotal->cents);
        $this->assertSame(37500, $this->statement($result, 'mv-3')->allocableTotal->cents);
        $this->assertSame(181, $this->statement($result, 'mv-1')->usageDays());
        $this->assertSame(184, $this->statement($result, 'mv-2')->usageDays());
        $this->assertSame(100000, 30993 + 31507 + 37500);
        $this->assertTrue($result->ownerOverview->isBalanced());
    }

    #[Test]
    public function mieterwechsel_im_schaltjahr_nutzt_366_tage(): void
    {
        // 1.000,00 EUR, eine Einheit, 182/366 und 184/366:
        // 100.000 × 182 / 366 = 49.726,7760 → 49.727 (größter Rest)
        // 100.000 × 184 / 366 = 50.273,2240 → 50.273
        $result = $this->calculate(
            DatePeriodRange::calendarYear(2024),
            [$this->unit('W-1', '80.00')],
            [
                $this->tenancy('mv-1', 'W-1', '2024-01-01', '2024-06-30'),
                $this->tenancy('mv-2', 'W-1', '2024-07-01', '2024-12-31'),
            ],
            [$this->cost('k-1', 'MUELL', 'Müllbeseitigung', '1000.00', 'flaeche')],
            ['flaeche' => new LivingAreaKey(['W-1' => '80.00'])]
        );

        $this->assertSame(49727, $this->statement($result, 'mv-1')->allocableTotal->cents);
        $this->assertSame(50273, $this->statement($result, 'mv-2')->allocableTotal->cents);
        $this->assertSame(182, $this->statement($result, 'mv-1')->usageDays());
        $this->assertSame(184, $this->statement($result, 'mv-2')->usageDays());
    }

    #[Test]
    public function drei_mieterwechsel_in_einer_einheit_werden_lueckenlos_verteilt(): void
    {
        // 3.650,00 EUR auf eine Einheit, Nutzungszeiträume 90, 91 und 184 Tage
        // (01.01. bis 31.03., 01.04. bis 30.06., 01.07. bis 31.12.):
        // 365.000 × 90 / 365 = 90.000; × 91 / 365 = 91.000; × 184 / 365 = 184.000
        $result = $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-1', '100.00')],
            [
                $this->tenancy('mv-1', 'W-1', '2025-01-01', '2025-03-31'),
                $this->tenancy('mv-2', 'W-1', '2025-04-01', '2025-06-30'),
                $this->tenancy('mv-3', 'W-1', '2025-07-01', '2025-12-31'),
            ],
            [$this->cost('k-1', 'ALLGEMEINSTROM', 'Allgemeinstrom', '3650.00', 'flaeche')],
            ['flaeche' => new LivingAreaKey(['W-1' => '100.00'])]
        );

        $this->assertSame(90000, $this->statement($result, 'mv-1')->allocableTotal->cents);
        $this->assertSame(91000, $this->statement($result, 'mv-2')->allocableTotal->cents);
        $this->assertSame(184000, $this->statement($result, 'mv-3')->allocableTotal->cents);
        $this->assertSame(365000, 90000 + 91000 + 184000);
        $this->assertSame(3, $result->statementCount());
        $this->assertSame([], $result->findingsWithCode(CheckCode::COVERAGE_GAP));
    }

    #[Test]
    public function leerstand_am_jahresanfang_wird_dem_eigentuemer_zugerechnet(): void
    {
        // 3.650,00 EUR, Leerstand 01.01. bis 31.03. (90 Tage), Miete ab 01.04.
        // Leerstand: 365.000 × 90 / 365 = 90.000 Cent
        $result = $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-1', '100.00')],
            [
                OccupancyInput::vacancy('leer-1', 'W-1', DatePeriodRange::fromIso('2025-01-01', '2025-03-31')),
                $this->tenancy('mv-1', 'W-1', '2025-04-01', '2025-12-31'),
            ],
            [$this->cost('k-1', 'GRUNDSTEUER', 'Grundsteuer', '3650.00', 'flaeche')],
            ['flaeche' => new LivingAreaKey(['W-1' => '100.00'])]
        );

        $this->assertSame(1, $result->statementCount());
        $this->assertSame(275000, $this->statement($result, 'mv-1')->allocableTotal->cents);
        $this->assertSame(90000, $result->ownerOverview->vacancyTotal->cents);
        $this->assertCount(1, $result->ownerOverview->vacancyShares);
        $this->assertSame(OccupancyKind::VACANCY, $result->ownerOverview->vacancyShares[0]->kind);
        $this->assertSame(90, $result->ownerOverview->vacancyShares[0]->days());
        $this->assertTrue($result->ownerOverview->isBalanced());
    }

    #[Test]
    public function leerstand_in_der_jahresmitte_wird_getrennt_ausgewiesen(): void
    {
        // Leerstand 01.06. bis 31.08. (92 Tage) zwischen zwei Mietverhältnissen.
        // 365.000 × 92 / 365 = 92.000 Cent zulasten Eigentümer.
        $result = $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-1', '100.00')],
            [
                $this->tenancy('mv-1', 'W-1', '2025-01-01', '2025-05-31'),
                OccupancyInput::vacancy('leer-1', 'W-1', DatePeriodRange::fromIso('2025-06-01', '2025-08-31')),
                $this->tenancy('mv-2', 'W-1', '2025-09-01', '2025-12-31'),
            ],
            [$this->cost('k-1', 'GRUNDSTEUER', 'Grundsteuer', '3650.00', 'flaeche')],
            ['flaeche' => new LivingAreaKey(['W-1' => '100.00'])]
        );

        $this->assertSame(151000, $this->statement($result, 'mv-1')->allocableTotal->cents);
        $this->assertSame(122000, $this->statement($result, 'mv-2')->allocableTotal->cents);
        $this->assertSame(92000, $result->ownerOverview->vacancyTotal->cents);
        $this->assertSame(365000, 151000 + 122000 + 92000);
    }

    #[Test]
    public function leerstand_am_jahresende_wird_getrennt_ausgewiesen(): void
    {
        // Leerstand Dezember (31 Tage): 365.000 × 31 / 365 = 31.000 Cent
        $result = $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-1', '100.00')],
            [
                $this->tenancy('mv-1', 'W-1', '2025-01-01', '2025-11-30'),
                OccupancyInput::vacancy('leer-1', 'W-1', DatePeriodRange::fromIso('2025-12-01', '2025-12-31')),
            ],
            [$this->cost('k-1', 'GRUNDSTEUER', 'Grundsteuer', '3650.00', 'flaeche')],
            ['flaeche' => new LivingAreaKey(['W-1' => '100.00'])]
        );

        $this->assertSame(334000, $this->statement($result, 'mv-1')->allocableTotal->cents);
        $this->assertSame(31000, $result->ownerOverview->vacancyTotal->cents);
    }

    #[Test]
    public function nicht_belegter_zeitraum_wird_ergaenzt_und_als_warnung_gemeldet(): void
    {
        // Ohne erfassten Leerstand bleibt der Zeitraum 01.12. bis 31.12. offen.
        // Die Engine ergänzt ihn als nicht belegten Zeitraum zulasten Eigentümer.
        $result = $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-1', '100.00')],
            [$this->tenancy('mv-1', 'W-1', '2025-01-01', '2025-11-30')],
            [$this->cost('k-1', 'GRUNDSTEUER', 'Grundsteuer', '3650.00', 'flaeche')],
            ['flaeche' => new LivingAreaKey(['W-1' => '100.00'])]
        );

        $gaps = $result->findingsWithCode(CheckCode::COVERAGE_GAP);
        $this->assertCount(1, $gaps);
        $this->assertSame(CheckSeverity::WARNING, $gaps[0]->severity);
        $this->assertStringContainsString('01.12.2025 bis 31.12.2025', $gaps[0]->message);
        $this->assertSame(31000, $result->ownerOverview->vacancyTotal->cents);
        $this->assertSame(
            OccupancyKind::OWNER_RESIDUAL,
            $result->ownerOverview->vacancyShares[0]->kind
        );
        $this->assertFalse($result->blocksFinalization());
    }

    #[Test]
    public function ueberschneidende_mietzeitraeume_loesen_eine_domain_exception_aus(): void
    {
        $this->expectException(OverlappingOccupancyException::class);
        $this->expectExceptionMessage('überschneiden sich');

        $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-1', '100.00')],
            [
                $this->tenancy('mv-1', 'W-1', '2025-01-01', '2025-07-15'),
                $this->tenancy('mv-2', 'W-1', '2025-07-01', '2025-12-31'),
            ],
            [$this->cost('k-1', 'GRUNDSTEUER', 'Grundsteuer', '3650.00', 'flaeche')],
            ['flaeche' => new LivingAreaKey(['W-1' => '100.00'])]
        );
    }

    #[Test]
    public function summe_der_einzelanteile_entspricht_exakt_den_kosten(): void
    {
        // Sieben Einheiten zu gleichen Teilen an 1.000,00 EUR:
        // 100.000 / 7 = 14.285,714... → fünf Einheiten erhalten 14.286 Cent,
        // zwei erhalten 14.285 Cent. Summe exakt 100.000 Cent.
        $units = [];
        $occupancies = [];
        $unitKeys = [];

        foreach (range(1, 7) as $index) {
            $unitKey = 'W-'.$index;
            $unitKeys[] = $unitKey;
            $units[] = $this->unit($unitKey, '50.00');
            $occupancies[] = $this->tenancy('mv-'.$index, $unitKey, '2025-01-01', '2025-12-31');
        }

        $result = $this->calculate(
            DatePeriodRange::calendarYear(2025),
            $units,
            $occupancies,
            [$this->cost('k-1', 'GEBAEUDEREINIGUNG', 'Gebäudereinigung', '1000.00', 'einheiten')],
            ['einheiten' => UnitCountKey::forUnits($unitKeys)]
        );

        $sum = 0;

        foreach ($result->statements as $statement) {
            $sum += $statement->allocableTotal->cents;
        }

        $this->assertSame(100000, $sum);
        $this->assertSame(14286, $this->statement($result, 'mv-1')->allocableTotal->cents);
        $this->assertSame(14285, $this->statement($result, 'mv-7')->allocableTotal->cents);
        $this->assertTrue($result->ownerOverview->isBalanced());
        $this->assertTrue($result->hasFinding(CheckCode::CHECKSUM_BALANCED));
    }

    #[Test]
    public function rundungsausgleich_wird_in_der_zeile_gespeichert(): void
    {
        // Drei Einheiten je 1/3 von 100,00 EUR: exakt 3.333,33 Cent.
        // W-1 erhält über das Largest-Remainder-Verfahren einen Cent mehr.
        $result = $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-1', '50.00'), $this->unit('W-2', '50.00'), $this->unit('W-3', '50.00')],
            [
                $this->tenancy('mv-1', 'W-1', '2025-01-01', '2025-12-31'),
                $this->tenancy('mv-2', 'W-2', '2025-01-01', '2025-12-31'),
                $this->tenancy('mv-3', 'W-3', '2025-01-01', '2025-12-31'),
            ],
            [$this->cost('k-1', 'GARTENPFLEGE', 'Gartenpflege', '100.00', 'einheiten')],
            ['einheiten' => UnitCountKey::forUnits(['W-1', 'W-2', 'W-3'])]
        );

        $this->assertSame(3334, $this->statement($result, 'mv-1')->allocableTotal->cents);
        $this->assertSame(1, $this->statement($result, 'mv-1')->lines[0]->roundingAdjustmentCent);
        $this->assertSame(0, $this->statement($result, 'mv-2')->lines[0]->roundingAdjustmentCent);
        $this->assertSame(1, $this->statement($result, 'mv-1')->totalRoundingAdjustmentCent());
        $this->assertTrue($this->statement($result, 'mv-1')->linesMatchAllocableTotal());
    }

    #[Test]
    public function mea_schluessel_ohne_volle_abdeckung_weist_einen_restanteil_aus(): void
    {
        // MEA 187,50 von 1.000,00: nur 18,75 Prozent der Kosten entfallen auf
        // die abgerechnete Einheit. 8.000,00 EUR × 0,1875 = 1.500,00 EUR.
        // Restanteil 6.500,00 EUR verbleibt beim Eigentümer.
        $result = $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-12', '72.50', '187.50')],
            [$this->tenancy('mv-1', 'W-12', '2025-01-01', '2025-12-31')],
            [$this->cost('k-1', 'VERSICHERUNG', 'Gebäudeversicherung', '8000.00', 'mea')],
            ['mea' => CoOwnershipShareKey::withTotalShares(['W-12' => '187.50'], '1000.00')]
        );

        $this->assertSame(150000, $this->statement($result, 'mv-1')->allocableTotal->cents);
        $this->assertSame(650000, $result->ownerOverview->residualTotal->cents);
        $this->assertCount(1, $result->ownerOverview->residualShares);
        $this->assertTrue($result->hasFinding(CheckCode::UNALLOCATED_RESIDUAL));
        $this->assertTrue($result->ownerOverview->isBalanced());
    }

    #[Test]
    public function personentageschluessel_gewichtet_die_zeit_nicht_doppelt(): void
    {
        // Personentage: mv-1 2 Personen × 365 = 730, mv-2 3 Personen × 181 = 543,
        // mv-3 1 Person × 184 = 184. Nenner 1.457.
        // 1.457,00 EUR ergeben exakt 10 Cent je Personentag:
        // mv-1 730,00 EUR, mv-2 543,00 EUR, mv-3 184,00 EUR.
        $billingPeriod = DatePeriodRange::calendarYear(2025);

        $result = $this->calculate(
            $billingPeriod,
            [$this->unit('W-1', '70.00'), $this->unit('W-2', '70.00')],
            [
                $this->tenancy('mv-1', 'W-1', '2025-01-01', '2025-12-31'),
                $this->tenancy('mv-2', 'W-2', '2025-01-01', '2025-06-30'),
                $this->tenancy('mv-3', 'W-2', '2025-07-01', '2025-12-31'),
            ],
            [$this->cost('k-1', 'MUELL', 'Müllbeseitigung', '1457.00', 'personentage')],
            [
                'personentage' => PersonDaysKey::fromSegments([
                    new PersonDaysSegment('mv-1', 2, $billingPeriod),
                    new PersonDaysSegment('mv-2', 3, DatePeriodRange::fromIso('2025-01-01', '2025-06-30')),
                    new PersonDaysSegment('mv-3', 1, DatePeriodRange::fromIso('2025-07-01', '2025-12-31')),
                ], $billingPeriod),
            ]
        );

        $this->assertSame(73000, $this->statement($result, 'mv-1')->allocableTotal->cents);
        $this->assertSame(54300, $this->statement($result, 'mv-2')->allocableTotal->cents);
        $this->assertSame(18400, $this->statement($result, 'mv-3')->allocableTotal->cents);
        $this->assertTrue($this->statement($result, 'mv-2')->lines[0]->timeFactor->includedInAllocationKey);
        $this->assertSame(
            '181 von 365 Tagen (Zeitanteil im Verteilerschlüssel enthalten)',
            $this->statement($result, 'mv-2')->lines[0]->timeFactor->explanation()
        );
    }

    #[Test]
    public function leerstand_erhaelt_keine_personentage(): void
    {
        // Der Leerstand hat keine Personen und damit keinen Anteil an den
        // personentagebezogenen Kosten.
        $billingPeriod = DatePeriodRange::calendarYear(2025);

        $result = $this->calculate(
            $billingPeriod,
            [$this->unit('W-1', '70.00')],
            [
                $this->tenancy('mv-1', 'W-1', '2025-01-01', '2025-09-30'),
                OccupancyInput::vacancy('leer-1', 'W-1', DatePeriodRange::fromIso('2025-10-01', '2025-12-31')),
            ],
            [$this->cost('k-1', 'MUELL', 'Müllbeseitigung', '546.00', 'personentage')],
            [
                'personentage' => PersonDaysKey::fromSegments([
                    new PersonDaysSegment('mv-1', 2, DatePeriodRange::fromIso('2025-01-01', '2025-09-30')),
                ], $billingPeriod),
            ]
        );

        $this->assertSame(54600, $this->statement($result, 'mv-1')->allocableTotal->cents);
        $this->assertSame(0, $result->ownerOverview->vacancyTotal->cents);
        $this->assertTrue($result->ownerOverview->isBalanced());
    }

    #[Test]
    public function verbrauchsschluessel_mit_zwischenablesung_teilt_nach_ablesewert(): void
    {
        // Verbrauch mv-1 61,000 m³, mv-2 39,000 m³ von 100,000 m³.
        // 700,00 EUR → 427,00 EUR und 273,00 EUR.
        $result = $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-1', '62.50')],
            [
                $this->tenancy('mv-1', 'W-1', '2025-01-01', '2025-06-30'),
                $this->tenancy('mv-2', 'W-1', '2025-07-01', '2025-12-31'),
            ],
            [$this->cost('k-1', 'WASSER', 'Wasserversorgung', '700.00', 'verbrauch')],
            ['verbrauch' => ConsumptionKey::create(['mv-1' => '61.000', 'mv-2' => '39.000'], 'm³')]
        );

        $this->assertSame(42700, $this->statement($result, 'mv-1')->allocableTotal->cents);
        $this->assertSame(27300, $this->statement($result, 'mv-2')->allocableTotal->cents);
        $this->assertFalse($this->statement($result, 'mv-1')->lines[0]->substituteDistributionConfirmed);
        $this->assertSame(
            'Verbrauch 61,000 m³ von 100,000 m³',
            $this->statement($result, 'mv-1')->lines[0]->allocationExplanation
        );
    }

    #[Test]
    public function bestaetigte_ersatzverteilung_wird_in_der_zeile_gekennzeichnet(): void
    {
        $result = $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-1', '62.50')],
            [
                $this->tenancy('mv-1', 'W-1', '2025-01-01', '2025-06-30'),
                $this->tenancy('mv-2', 'W-1', '2025-07-01', '2025-12-31'),
            ],
            [$this->cost('k-1', 'WASSER', 'Wasserversorgung', '730.00', 'verbrauch')],
            [
                'verbrauch' => ConsumptionKey::create(
                    ['mv-1' => '49.589', 'mv-2' => '50.411'],
                    'm³',
                    ['mv-1', 'mv-2']
                ),
            ]
        );

        $line = $this->statement($result, 'mv-1')->lines[0];
        $this->assertTrue($line->substituteDistributionConfirmed);
        $this->assertTrue($result->hasFinding(CheckCode::SUBSTITUTE_CONSUMPTION_DISTRIBUTION));
        $this->assertNotEmpty($this->statement($result, 'mv-1')->assumptions);
        $this->assertStringContainsString(
            'ausdrücklich bestätigte Ersatzverteilung',
            implode(' ', $this->statement($result, 'mv-1')->assumptions)
        );
    }

    #[Test]
    public function direktzuordnung_bei_mieterwechsel_ordnet_jedem_zeitraum_seinen_betrag_zu(): void
    {
        // Externe Heizkostenabrechnung: mv-1 610,00 EUR, mv-2 480,00 EUR,
        // Gesamtbetrag 1.090,00 EUR.
        $result = $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-1', '62.50')],
            [
                $this->tenancy('mv-1', 'W-1', '2025-01-01', '2025-06-30'),
                $this->tenancy('mv-2', 'W-1', '2025-07-01', '2025-12-31'),
            ],
            [$this->cost('k-1', 'HEIZUNG', 'Heizung', '1090.00', 'heizung')],
            [
                'heizung' => DirectAssignmentKey::fromAmounts([
                    'mv-1' => Money::fromEuros('610.00'),
                    'mv-2' => Money::fromEuros('480.00'),
                ]),
            ]
        );

        $this->assertSame(61000, $this->statement($result, 'mv-1')->allocableTotal->cents);
        $this->assertSame(48000, $this->statement($result, 'mv-2')->allocableTotal->cents);
        $this->assertSame(
            'Direktzuordnung 610,00 EUR von 1.090,00 EUR',
            $this->statement($result, 'mv-1')->lines[0]->allocationExplanation
        );
        $this->assertTrue($result->ownerOverview->isBalanced());
    }

    #[Test]
    public function gutschrift_ergibt_negative_anteile_und_bleibt_exakt(): void
    {
        // Gutschrift 181,00 EUR nach Wohnfläche 62,50 / 37,50 m²:
        // exakt -11.312,50 und -6.787,50 Cent, Reste gleich, der Cent geht an
        // den alphabetisch ersten Schlüssel mv-a.
        $result = $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-1', '62.50'), $this->unit('W-2', '37.50')],
            [
                $this->tenancy('mv-a', 'W-1', '2025-01-01', '2025-12-31'),
                $this->tenancy('mv-b', 'W-2', '2025-01-01', '2025-12-31'),
            ],
            [
                new CostItemInput(
                    'k-gutschrift',
                    'GARTENPFLEGE',
                    'Gutschrift Gartenpflege',
                    Money::fromEuros('-181.00'),
                    'flaeche',
                    AllocabilityStatus::ALLOCABLE,
                    DatePeriodRange::calendarYear(2025),
                    null,
                    TaxBenefitCategory::NONE,
                    null,
                    true,
                    'Gutschrift G-2025-004',
                    true
                ),
            ],
            ['flaeche' => new LivingAreaKey(['W-1' => '62.50', 'W-2' => '37.50'])]
        );

        $this->assertSame(-11312, $this->statement($result, 'mv-a')->allocableTotal->cents);
        $this->assertSame(-6788, $this->statement($result, 'mv-b')->allocableTotal->cents);
        $this->assertSame(-18100, -11312 + -6788);
        $this->assertTrue($this->statement($result, 'mv-a')->lines[0]->isCreditNote());
        $this->assertTrue($result->hasFinding(CheckCode::CREDIT_NOTE_APPLIED));
        $this->assertTrue($this->statement($result, 'mv-a')->isCredit());
        $this->assertSame(11312, $this->statement($result, 'mv-a')->credit()->cents);
    }

    #[Test]
    public function nicht_umlagefaehige_kosten_werden_ausgeschlossen(): void
    {
        $result = $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-1', '100.00')],
            [$this->tenancy('mv-1', 'W-1', '2025-01-01', '2025-12-31')],
            [
                $this->cost('k-1', 'WASSER', 'Wasserversorgung', '600.00', 'flaeche'),
                new CostItemInput(
                    'k-2',
                    'VERWALTUNG',
                    'Verwaltervergütung',
                    Money::fromEuros('288.00'),
                    'flaeche',
                    AllocabilityStatus::NOT_ALLOCABLE
                ),
                new CostItemInput(
                    'k-3',
                    'INSTANDHALTUNG',
                    'Reparatur Dachrinne',
                    Money::fromEuros('450.00'),
                    'flaeche',
                    AllocabilityStatus::NOT_ALLOCABLE
                ),
                new CostItemInput(
                    'k-4',
                    'SONSTIGE',
                    'Sammelposition ohne Aufschlüsselung',
                    Money::fromEuros('120.00'),
                    'flaeche',
                    AllocabilityStatus::REVIEW_REQUIRED
                ),
            ],
            ['flaeche' => new LivingAreaKey(['W-1' => '100.00'])]
        );

        $this->assertSame(60000, $this->statement($result, 'mv-1')->allocableTotal->cents);
        $this->assertCount(1, $this->statement($result, 'mv-1')->lines);
        $this->assertCount(3, $result->ownerOverview->excludedCosts);
        $this->assertSame(85800, $result->ownerOverview->excludedCostTotal->cents);
        $this->assertCount(3, $result->findingsWithCode(CheckCode::NOT_ALLOCABLE_EXCLUDED));
        $this->assertSame(145800, $result->ownerOverview->grossCostTotal()->cents);
    }

    #[Test]
    public function bewusste_einbeziehung_erfordert_begruendung_und_wird_gekennzeichnet(): void
    {
        $result = $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-1', '100.00')],
            [$this->tenancy('mv-1', 'W-1', '2025-01-01', '2025-12-31')],
            [
                new CostItemInput(
                    'k-1',
                    'SONSTIGE',
                    'Wartung Rauchwarnmelder',
                    Money::fromEuros('96.00'),
                    'flaeche',
                    AllocabilityStatus::REVIEW_REQUIRED,
                    null,
                    'Im Mietvertrag § 5 Abs. 3 ausdrücklich als sonstige Betriebskosten vereinbart.'
                ),
            ],
            ['flaeche' => new LivingAreaKey(['W-1' => '100.00'])]
        );

        $statement = $result->statement('mv-1');
        $this->assertNotNull($statement);
        $this->assertSame(9600, $statement->allocableTotal->cents);
        $this->assertCount(1, $statement->linesIncludedByOverride());
        $this->assertTrue($statement->lines[0]->includedByOverride);
        $this->assertSame(AllocabilityStatus::REVIEW_REQUIRED, $statement->lines[0]->allocabilityStatus);
        $this->assertTrue($result->hasFinding(CheckCode::NOT_ALLOCABLE_INCLUDED_BY_OVERRIDE));
        $this->assertStringContainsString(
            'keine juristische Freigabe',
            $result->findingsWithCode(CheckCode::NOT_ALLOCABLE_INCLUDED_BY_OVERRIDE)[0]->message
        );
        $this->assertStringContainsString('Mietvertrag § 5 Abs. 3', implode(' ', $statement->assumptions));
    }

    #[Test]
    public function tatsaechlich_geleistete_vorauszahlungen_werden_abgezogen(): void
    {
        // Umlagefähige Kosten 1.200,00 EUR, Soll 1.500,00 EUR,
        // tatsächlich gezahlt 1.375,00 EUR → Guthaben 175,00 EUR.
        $result = $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-1', '100.00')],
            [$this->tenancy('mv-1', 'W-1', '2025-01-01', '2025-12-31')],
            [$this->cost('k-1', 'WASSER', 'Wasserversorgung', '1200.00', 'flaeche')],
            ['flaeche' => new LivingAreaKey(['W-1' => '100.00'])],
            [PrepaymentInput::actual('mv-1', Money::fromEuros('1375.00'), Money::fromEuros('1500.00'))]
        );

        $statement = $result->statement('mv-1');
        $this->assertNotNull($statement);
        $this->assertSame(137500, $statement->prepaymentActual->cents);
        $this->assertSame(150000, $statement->prepaymentTarget->cents);
        $this->assertSame(-17500, $statement->balance->cents);
        $this->assertTrue($statement->isCredit());
        $this->assertSame(17500, $statement->credit()->cents);
        $this->assertFalse($statement->prepaymentAssumedFromTarget);
        $this->assertTrue($result->hasFinding(CheckCode::PREPAYMENT_DEVIATION));
    }

    #[Test]
    public function uebernahme_der_sollwerte_wird_als_annahme_gekennzeichnet(): void
    {
        $result = $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-1', '100.00')],
            [$this->tenancy('mv-1', 'W-1', '2025-01-01', '2025-12-31')],
            [$this->cost('k-1', 'WASSER', 'Wasserversorgung', '1800.00', 'flaeche')],
            ['flaeche' => new LivingAreaKey(['W-1' => '100.00'])],
            [PrepaymentInput::assumedFromTarget('mv-1', Money::fromEuros('1500.00'))]
        );

        $statement = $result->statement('mv-1');
        $this->assertNotNull($statement);
        $this->assertTrue($statement->prepaymentAssumedFromTarget);
        $this->assertSame(150000, $statement->prepaymentActual->cents);
        $this->assertSame(30000, $statement->balance->cents);
        $this->assertTrue($statement->isAdditionalPayment());
        $this->assertTrue($result->hasFinding(CheckCode::PREPAYMENT_ASSUMED_FROM_TARGET));
        $this->assertStringContainsString(
            'Sollvorauszahlungen',
            implode(' ', $statement->assumptions)
        );
    }

    #[Test]
    public function fehlende_vorauszahlungsdaten_werden_gemeldet_und_nicht_geschaetzt(): void
    {
        $result = $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-1', '100.00')],
            [$this->tenancy('mv-1', 'W-1', '2025-01-01', '2025-12-31')],
            [$this->cost('k-1', 'WASSER', 'Wasserversorgung', '1200.00', 'flaeche')],
            ['flaeche' => new LivingAreaKey(['W-1' => '100.00'])]
        );

        $statement = $result->statement('mv-1');
        $this->assertNotNull($statement);
        $this->assertSame(0, $statement->prepaymentActual->cents);
        $this->assertSame(120000, $statement->balance->cents);
        $this->assertTrue($result->hasFinding(CheckCode::PREPAYMENT_MISSING));
    }

    #[Test]
    public function paragraph_35a_lohnanteile_werden_getrennt_summiert(): void
    {
        // Gartenpflege 1.000,00 EUR mit Lohnanteil 600,00 EUR (haushaltsnahe
        // Dienstleistung), Schornsteinfeger 200,00 EUR mit Lohnanteil
        // 200,00 EUR (Handwerkerleistung), Anteil der Einheit 50 Prozent.
        $result = $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-1', '50.00'), $this->unit('W-2', '50.00')],
            [
                $this->tenancy('mv-1', 'W-1', '2025-01-01', '2025-12-31'),
                $this->tenancy('mv-2', 'W-2', '2025-01-01', '2025-12-31'),
            ],
            [
                new CostItemInput(
                    'k-1',
                    'GARTENPFLEGE',
                    'Gartenpflege',
                    Money::fromEuros('1000.00'),
                    'flaeche',
                    AllocabilityStatus::ALLOCABLE,
                    null,
                    null,
                    TaxBenefitCategory::HOUSEHOLD_SERVICE,
                    Money::fromEuros('600.00')
                ),
                new CostItemInput(
                    'k-2',
                    'SCHORNSTEINFEGER',
                    'Schornsteinreinigung',
                    Money::fromEuros('200.00'),
                    'flaeche',
                    AllocabilityStatus::ALLOCABLE,
                    null,
                    null,
                    TaxBenefitCategory::CRAFTSMAN_SERVICE,
                    Money::fromEuros('200.00')
                ),
            ],
            ['flaeche' => new LivingAreaKey(['W-1' => '50.00', 'W-2' => '50.00'])]
        );

        $statement = $result->statement('mv-1');
        $this->assertNotNull($statement);
        $this->assertSame(60000, $statement->allocableTotal->cents);
        $this->assertSame(30000, $statement->taxBenefitHouseholdServices->cents);
        $this->assertSame(10000, $statement->taxBenefitCraftsmanServices->cents);
        $this->assertSame(40000, $statement->taxBenefitTotal()->cents);
        $this->assertCount(1, $statement->linesWithTaxBenefit(TaxBenefitCategory::HOUSEHOLD_SERVICE));
    }

    #[Test]
    public function nicht_ausgewiesener_lohnanteil_wird_gekennzeichnet_und_nicht_geschaetzt(): void
    {
        $result = $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-1', '100.00')],
            [$this->tenancy('mv-1', 'W-1', '2025-01-01', '2025-12-31')],
            [
                new CostItemInput(
                    'k-1',
                    'HAUSWART',
                    'Hauswart',
                    Money::fromEuros('480.00'),
                    'flaeche',
                    AllocabilityStatus::ALLOCABLE,
                    null,
                    null,
                    TaxBenefitCategory::HOUSEHOLD_SERVICE,
                    null,
                    false
                ),
            ],
            ['flaeche' => new LivingAreaKey(['W-1' => '100.00'])]
        );

        $statement = $result->statement('mv-1');
        $this->assertNotNull($statement);
        $this->assertSame(48000, $statement->allocableTotal->cents);
        $this->assertSame(0, $statement->taxBenefitHouseholdServices->cents);
        $this->assertNull($statement->lines[0]->taxBenefitLaborShare);
        $this->assertTrue($statement->lines[0]->hasUndisclosedLaborShare());
        $this->assertTrue($result->hasFinding(CheckCode::UNDISCLOSED_LABOR_SHARE));
        $this->assertStringContainsString(
            'Lohnanteil nach § 35a EStG nicht ausgewiesen',
            implode(' ', $statement->assumptions)
        );
    }

    #[Test]
    public function einheit_ohne_schluesselwert_loest_eine_domain_exception_aus(): void
    {
        $this->expectException(InvalidAllocationKeyException::class);
        $this->expectExceptionMessage('keinen Wert für die Einheit');

        $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-1', '100.00'), $this->unit('W-2', '80.00')],
            [
                $this->tenancy('mv-1', 'W-1', '2025-01-01', '2025-12-31'),
                $this->tenancy('mv-2', 'W-2', '2025-01-01', '2025-12-31'),
            ],
            [$this->cost('k-1', 'WASSER', 'Wasserversorgung', '600.00', 'flaeche')],
            ['flaeche' => new LivingAreaKey(['W-1' => '100.00'])]
        );
    }

    #[Test]
    public function unbekannter_verteilerschluessel_loest_eine_domain_exception_aus(): void
    {
        $this->expectException(CalculationInputException::class);
        $this->expectExceptionMessage('nicht übergeben worden');

        $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-1', '100.00')],
            [$this->tenancy('mv-1', 'W-1', '2025-01-01', '2025-12-31')],
            [$this->cost('k-1', 'WASSER', 'Wasserversorgung', '600.00', 'unbekannt')],
            ['flaeche' => new LivingAreaKey(['W-1' => '100.00'])]
        );
    }

    #[Test]
    public function nutzungszeitraum_ausserhalb_des_abrechnungszeitraums_ist_ein_eingabefehler(): void
    {
        $this->expectException(CalculationInputException::class);
        $this->expectExceptionMessage('vollständig außerhalb');

        $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-1', '100.00')],
            [$this->tenancy('mv-1', 'W-1', '2024-01-01', '2024-12-31')],
            [$this->cost('k-1', 'WASSER', 'Wasserversorgung', '600.00', 'flaeche')],
            ['flaeche' => new LivingAreaKey(['W-1' => '100.00'])]
        );
    }

    #[Test]
    public function doppelter_kostenschluessel_ist_ein_eingabefehler(): void
    {
        $this->expectException(CalculationInputException::class);
        $this->expectExceptionMessage('mehrfach übergeben');

        $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-1', '100.00')],
            [$this->tenancy('mv-1', 'W-1', '2025-01-01', '2025-12-31')],
            [
                $this->cost('k-1', 'WASSER', 'Wasserversorgung', '600.00', 'flaeche'),
                $this->cost('k-1', 'MUELL', 'Müllbeseitigung', '300.00', 'flaeche'),
            ],
            ['flaeche' => new LivingAreaKey(['W-1' => '100.00'])]
        );
    }

    #[Test]
    public function nutzungszeitraum_wird_auf_den_abrechnungszeitraum_begrenzt(): void
    {
        // Mietbeginn 15.09.2024, Abrechnungszeitraum 2025: es zählen 365 Tage.
        $result = $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-1', '100.00')],
            [$this->tenancy('mv-1', 'W-1', '2024-09-15', '2025-12-31')],
            [$this->cost('k-1', 'WASSER', 'Wasserversorgung', '1200.00', 'flaeche')],
            ['flaeche' => new LivingAreaKey(['W-1' => '100.00'])]
        );

        $statement = $result->statement('mv-1');
        $this->assertNotNull($statement);
        $this->assertSame(365, $statement->usageDays());
        $this->assertSame('2025-01-01', $statement->usagePeriod->startIso());
        $this->assertSame(120000, $statement->allocableTotal->cents);
    }

    #[Test]
    public function kosten_ausserhalb_des_abrechnungszeitraums_werden_gemeldet(): void
    {
        $result = $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-1', '100.00')],
            [$this->tenancy('mv-1', 'W-1', '2025-01-01', '2025-12-31')],
            [
                new CostItemInput(
                    'k-1',
                    'WASSER',
                    'Wasserversorgung',
                    Money::fromEuros('600.00'),
                    'flaeche',
                    AllocabilityStatus::ALLOCABLE,
                    DatePeriodRange::fromIso('2024-07-01', '2025-06-30')
                ),
                new CostItemInput(
                    'k-2',
                    'MUELL',
                    'Müllbeseitigung',
                    Money::fromEuros('300.00'),
                    'flaeche'
                ),
            ],
            ['flaeche' => new LivingAreaKey(['W-1' => '100.00'])]
        );

        $this->assertTrue($result->hasFinding(CheckCode::COST_OUTSIDE_BILLING_PERIOD));
        $this->assertTrue($result->hasFinding(CheckCode::COST_WITHOUT_SERVICE_PERIOD));
        $this->assertFalse($result->blocksFinalization());
    }

    #[Test]
    public function jede_zeile_enthaelt_den_vollstaendigen_rechenweg(): void
    {
        $result = $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-1', '62.50'), $this->unit('W-2', '37.50')],
            [
                $this->tenancy('mv-1', 'W-1', '2025-07-01', '2025-12-31'),
                $this->tenancy('mv-2', 'W-2', '2025-01-01', '2025-12-31'),
            ],
            [$this->cost('k-1', 'GRUNDSTEUER', 'Grundsteuer', '1000.00', 'flaeche')],
            ['flaeche' => new LivingAreaKey(['W-1' => '62.50', 'W-2' => '37.50'])]
        );

        $line = $this->statement($result, 'mv-1')->lines[0];
        $this->assertNotNull($line);
        $this->assertSame('GRUNDSTEUER', $line->categoryKey);
        $this->assertSame('Grundsteuer', $line->categoryLabel);
        $this->assertSame(100000, $line->totalCost->cents);
        $this->assertSame('Wohnfläche', $line->allocationKeyLabel);
        $this->assertSame('62,50', $line->numerator);
        $this->assertSame('100,00', $line->denominator);
        $this->assertSame(184, $line->timeFactor->daysUsed);
        $this->assertSame(365, $line->timeFactor->daysInPeriod);
        $this->assertSame(
            'Wohnfläche 62,50 m² von 100,00 m², Zeitanteil 184 von 365 Tagen',
            $line->calculationExplanation()
        );
        $this->assertSame(AllocabilityStatus::ALLOCABLE, $line->allocabilityStatus);
    }

    #[Test]
    public function eigentuemeruebersicht_fuehrt_alle_summen_und_pruefsummen(): void
    {
        $result = $this->calculate(
            DatePeriodRange::calendarYear(2025),
            [$this->unit('W-1', '100.00'), $this->unit('W-2', '100.00')],
            [
                $this->tenancy('mv-1', 'W-1', '2025-01-01', '2025-12-31'),
                $this->tenancy('mv-2', 'W-2', '2025-01-01', '2025-06-30'),
                OccupancyInput::vacancy('leer-1', 'W-2', DatePeriodRange::fromIso('2025-07-01', '2025-12-31')),
            ],
            [
                $this->cost('k-1', 'WASSER', 'Wasserversorgung', '3650.00', 'flaeche'),
                new CostItemInput(
                    'k-2',
                    'VERWALTUNG',
                    'Verwaltervergütung',
                    Money::fromEuros('500.00'),
                    'flaeche',
                    AllocabilityStatus::NOT_ALLOCABLE
                ),
            ],
            ['flaeche' => new LivingAreaKey(['W-1' => '100.00', 'W-2' => '100.00'])]
        );

        $overview = $result->ownerOverview;

        // Wasser: W-1 182.500 Cent, W-2 182.500 Cent, davon 181/365 Miete
        // (90.500 Cent) und 184/365 Leerstand (92.000 Cent).
        $this->assertSame(182500, $overview->statement('mv-1')?->allocableTotal->cents);
        $this->assertSame(90500, $overview->statement('mv-2')?->allocableTotal->cents);
        $this->assertSame(92000, $overview->vacancyTotal->cents);
        $this->assertSame(365000, $overview->includedCostTotal->cents);
        $this->assertSame(273000, $overview->allocatedToTenantsTotal->cents);
        $this->assertSame(50000, $overview->excludedCostTotal->cents);
        $this->assertSame(142000, $overview->ownerBurdenTotal()->cents);
        $this->assertSame(415000, $overview->grossCostTotal()->cents);
        $this->assertTrue($overview->isBalanced());
        $this->assertSame(0, $overview->checksumDifference()->cents);
        $this->assertCount(1, $overview->vacancySharesForUnit('W-2'));
    }

    /**
     * Liefert die Abrechnung eines Mietverhältnisses und stellt sicher, dass
     * sie erzeugt wurde.
     */
    private function statement(CalculationRunResult $result, string $occupancyKey): UnitStatementResult
    {
        $statement = $result->statement($occupancyKey);

        $this->assertNotNull($statement, 'Abrechnung fehlt: '.$occupancyKey);

        return $statement;
    }

    /**
     * @param  list<UnitInput>  $units
     * @param  list<OccupancyInput>  $occupancies
     * @param  list<CostItemInput>  $costItems
     * @param  array<string, AllocationKey>  $keys
     * @param  list<PrepaymentInput>  $prepayments
     */
    private function calculate(
        DatePeriodRange $period,
        array $units,
        array $occupancies,
        array $costItems,
        array $keys,
        array $prepayments = [],
    ): CalculationRunResult {
        return $this->calculator->calculate(new StatementCalculationInput(
            $period,
            $units,
            $occupancies,
            $costItems,
            $keys,
            $prepayments,
            'Testobjekt'
        ));
    }

    private function unit(string $unitKey, string $livingArea, ?string $coOwnershipShare = null): UnitInput
    {
        return new UnitInput(
            $unitKey,
            'Wohnung '.$unitKey,
            $livingArea,
            $livingArea,
            $coOwnershipShare
        );
    }

    private function tenancy(string $occupancyKey, string $unitKey, string $start, string $end): OccupancyInput
    {
        return OccupancyInput::tenancy(
            $occupancyKey,
            $unitKey,
            DatePeriodRange::fromIso($start, $end),
            'Mietverhältnis '.$occupancyKey
        );
    }

    private function cost(
        string $costItemKey,
        string $categoryKey,
        string $label,
        string $amount,
        string $allocationKeyRef,
    ): CostItemInput {
        return new CostItemInput(
            $costItemKey,
            $categoryKey,
            $label,
            Money::fromEuros($amount),
            $allocationKeyRef,
            AllocabilityStatus::ALLOCABLE,
            DatePeriodRange::calendarYear(2025)
        );
    }
}
