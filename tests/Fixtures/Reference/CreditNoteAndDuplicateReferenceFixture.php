<?php

declare(strict_types=1);

namespace Tests\Fixtures\Reference;

use App\Domain\Allocation\AllocationKey;
use App\Domain\Allocation\LivingAreaKey;
use App\Domain\Allocation\UnitCountKey;
use App\Domain\Calculation\AllocabilityStatus;
use App\Domain\Calculation\Check\InvoiceReference;
use App\Domain\Calculation\Check\PreviousYearCategoryAmount;
use App\Domain\Calculation\Dto\CostItemInput;
use App\Domain\Calculation\Dto\OccupancyInput;
use App\Domain\Calculation\Dto\PrepaymentInput;
use App\Domain\Calculation\Dto\StatementCalculationInput;
use App\Domain\Calculation\Dto\UnitInput;
use App\Domain\Calculation\TaxBenefitCategory;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;

/**
 * REFERENZ-FIXTURE 3: Lauf mit Rechnung, Gutschrift, erkannter Dublette und
 * Vorjahresabweichung.
 *
 * Abrechnungszeitraum 01.01.2025 bis 31.12.2025, 365 Tage.
 * Zwei Einheiten, jeweils ganzjährig vermietet:
 *   W-A  62,50 m²  mv-a  → Anteil 62,50 / 100,00 = 62,5 Prozent
 *   W-B  37,50 m²  mv-b  → Anteil 37,50 / 100,00 = 37,5 Prozent
 *
 * KOSTENPOSITIONEN
 *   k-01 Gartenpflege, Rechnung A-2025-311        1.200,00 EUR  umlagefähig
 *   k-02 Gartenpflege, Gutschrift G-2025-004       -181,00 EUR  umlagefähig
 *   k-03 Müllbeseitigung                            900,00 EUR  umlagefähig
 *   k-04 Wasserversorgung                         1.000,00 EUR  umlagefähig
 *   k-05 Gebäudeversicherung, Rechnung R-2025-118   640,00 EUR  umlagefähig
 *   k-06 Gebäudeversicherung, R-2025-118 erneut     640,00 EUR  prüfpflichtig,
 *        als mögliche Dublette NICHT umgelegt
 *   k-07 Schornsteinreinigung                       120,00 EUR  umlagefähig,
 *        Verteilung nach Einheiten (2 Einheiten, je 60,00 EUR)
 *
 *   Summe einbezogen: 1.200,00 - 181,00 + 900,00 + 1.000,00 + 640,00 + 120,00
 *                   = 3.679,00 EUR
 *   1.200,00 - 181,00 = 1.019,00
 *   1.019,00 + 900,00 = 1.919,00
 *   1.919,00 + 1.000,00 = 2.919,00
 *   2.919,00 + 640,00 = 3.559,00
 *   3.559,00 + 120,00 = 3.679,00
 *   Summe ausgeschlossen: 640,00 EUR (k-06)
 *
 * VERTEILUNG (Beträge in Cent)
 *   k-01  120.000 × 0,625 =  75.000  /  × 0,375 = 45.000
 *   k-02  Gutschrift -18.100:
 *         exakt -11.312,50 und -6.787,50; abgerundet -11.313 und -6.788,
 *         Summe -18.101, ein Cent ist zurückzugeben. Beide Reste sind
 *         gleich (0,5), deshalb entscheidet der Beteiligtenschlüssel
 *         aufsteigend: mv-a erhält den Cent.
 *         → mv-a -11.312, mv-b -6.788
 *   k-03   90.000 × 0,625 =  56.250  /  × 0,375 = 33.750
 *   k-04  100.000 × 0,625 =  62.500  /  × 0,375 = 37.500
 *   k-05   64.000 × 0,625 =  40.000  /  × 0,375 = 24.000
 *   k-07   12.000 / 2     =   6.000  je Einheit
 *
 *   mv-a: 75.000 - 11.312 + 56.250 + 62.500 + 40.000 + 6.000 = 228.438
 *         75.000 - 11.312 = 63.688; + 56.250 = 119.938; + 62.500 = 182.438;
 *         + 40.000 = 222.438; + 6.000 = 228.438  → 2.284,38 EUR
 *   mv-b: 45.000 -  6.788 + 33.750 + 37.500 + 24.000 + 6.000 = 139.462
 *         45.000 - 6.788 = 38.212; + 33.750 = 71.962; + 37.500 = 109.462;
 *         + 24.000 = 133.462; + 6.000 = 139.462  → 1.394,62 EUR
 *   Prüfsumme: 228.438 + 139.462 = 367.900 Cent = 3.679,00 EUR
 *
 * VORAUSZAHLUNGEN UND ERGEBNIS
 *   mv-a: 12 × 175,00 = 2.100,00 EUR → 2.284,38 - 2.100,00 = 184,38 NACHZAHLUNG
 *   mv-b: 12 × 120,00 = 1.440,00 EUR → 1.394,62 - 1.440,00 =  45,38 GUTHABEN
 *
 * DUBLETTENPRÜFUNG
 *   k-05 und k-06 haben denselben Lieferanten und dieselbe Rechnungsnummer
 *   R-2025-118 → eine Warnung. Die Gutschrift k-02 verweist auf die Rechnung
 *   k-01 und wird NICHT als Dublette gemeldet.
 *
 * VORJAHRESVERGLEICH (Schwelle 30 Prozent)
 *   Gartenpflege        Vorjahr   760,00 → jetzt 1.019,00: +259,00 = +34,1 % → Warnung
 *   Müllbeseitigung     Vorjahr   880,00 → jetzt   900,00: + 20,00 = + 2,3 % → keine Meldung
 *   Wasserversorgung    Vorjahr   980,00 → jetzt 1.000,00: + 20,00 = + 2,0 % → keine Meldung
 *   Gebäudeversicherung Vorjahr   620,00 → jetzt 1.280,00: +660,00 = +106,5 % → Warnung
 *                       (erklärt sich durch die doppelt erfasste Rechnung)
 *   Allgemeinstrom      Vorjahr   300,00 → fehlt in diesem Lauf → Warnung
 *   Schornsteinreinigung ohne Vorjahreswert → Hinweis
 */
final class CreditNoteAndDuplicateReferenceFixture
{
    public const string KEY_AREA = 'wohnflaeche';

    public const string KEY_UNITS = 'einheiten';

    public const int EXPECTED_MVA_CENT = 228438;

    public const int EXPECTED_MVB_CENT = 139462;

    public const int EXPECTED_INCLUDED_TOTAL_CENT = 367900;

    public const int EXPECTED_EXCLUDED_TOTAL_CENT = 64000;

    public const int EXPECTED_MVA_BALANCE_CENT = 18438;

    public const int EXPECTED_MVB_BALANCE_CENT = -4538;

    public static function billingPeriod(): DatePeriodRange
    {
        return DatePeriodRange::calendarYear(2025);
    }

    /**
     * @return list<UnitInput>
     */
    public static function units(): array
    {
        return [
            new UnitInput('W-A', 'Wohnung A', '62.50', '62.50'),
            new UnitInput('W-B', 'Wohnung B', '37.50', '37.50'),
        ];
    }

    /**
     * @return list<OccupancyInput>
     */
    public static function occupancies(): array
    {
        return [
            OccupancyInput::tenancy('mv-a', 'W-A', self::billingPeriod(), 'Mietverhältnis Wohnung A'),
            OccupancyInput::tenancy('mv-b', 'W-B', self::billingPeriod(), 'Mietverhältnis Wohnung B'),
        ];
    }

    /**
     * @return array<string, AllocationKey>
     */
    public static function allocationKeys(): array
    {
        return [
            self::KEY_AREA => new LivingAreaKey(['W-A' => '62.50', 'W-B' => '37.50']),
            self::KEY_UNITS => UnitCountKey::forUnits(['W-A', 'W-B']),
        ];
    }

    /**
     * @return list<CostItemInput>
     */
    public static function costItems(): array
    {
        $period = self::billingPeriod();

        return [
            new CostItemInput(
                'k-01',
                'GARTENPFLEGE',
                'Gartenpflege',
                Money::fromEuros('1200.00'),
                self::KEY_AREA,
                AllocabilityStatus::ALLOCABLE,
                $period,
                null,
                TaxBenefitCategory::NONE,
                null,
                true,
                'Rechnung A-2025-311, Grünwerk Gartenpflege GmbH'
            ),
            new CostItemInput(
                'k-02',
                'GARTENPFLEGE',
                'Gutschrift Gartenpflege',
                Money::fromEuros('-181.00'),
                self::KEY_AREA,
                AllocabilityStatus::ALLOCABLE,
                $period,
                null,
                TaxBenefitCategory::NONE,
                null,
                true,
                'Gutschrift G-2025-004, Grünwerk Gartenpflege GmbH',
                true
            ),
            new CostItemInput(
                'k-03',
                'MUELL',
                'Müllbeseitigung',
                Money::fromEuros('900.00'),
                self::KEY_AREA,
                AllocabilityStatus::ALLOCABLE,
                $period
            ),
            new CostItemInput(
                'k-04',
                'WASSER',
                'Wasserversorgung',
                Money::fromEuros('1000.00'),
                self::KEY_AREA,
                AllocabilityStatus::ALLOCABLE,
                $period
            ),
            new CostItemInput(
                'k-05',
                'VERSICHERUNG',
                'Gebäudeversicherung',
                Money::fromEuros('640.00'),
                self::KEY_AREA,
                AllocabilityStatus::ALLOCABLE,
                $period,
                null,
                TaxBenefitCategory::NONE,
                null,
                true,
                'Rechnung R-2025-118, Rheinische Versicherung AG'
            ),
            new CostItemInput(
                'k-06',
                'VERSICHERUNG',
                'Gebäudeversicherung, erneut erfasst',
                Money::fromEuros('640.00'),
                self::KEY_AREA,
                AllocabilityStatus::REVIEW_REQUIRED,
                $period,
                null,
                TaxBenefitCategory::NONE,
                null,
                true,
                'Rechnung R-2025-118, Rheinische Versicherung AG'
            ),
            new CostItemInput(
                'k-07',
                'SCHORNSTEINFEGER',
                'Schornsteinreinigung',
                Money::fromEuros('120.00'),
                self::KEY_UNITS,
                AllocabilityStatus::ALLOCABLE,
                $period
            ),
        ];
    }

    /**
     * @return list<PrepaymentInput>
     */
    public static function prepayments(): array
    {
        return [
            PrepaymentInput::actual('mv-a', Money::fromEuros('2100.00'), Money::fromEuros('2100.00'), '12 × 175,00 EUR'),
            PrepaymentInput::actual('mv-b', Money::fromEuros('1440.00'), Money::fromEuros('1440.00'), '12 × 120,00 EUR'),
        ];
    }

    public static function calculationInput(): StatementCalculationInput
    {
        return new StatementCalculationInput(
            self::billingPeriod(),
            self::units(),
            self::occupancies(),
            self::costItems(),
            self::allocationKeys(),
            self::prepayments(),
            'Zweifamilienhaus Beispielweg 4'
        );
    }

    /**
     * Belegmerkmale für die Dublettenprüfung.
     *
     * @return list<InvoiceReference>
     */
    public static function invoiceReferences(): array
    {
        return [
            new InvoiceReference(
                'k-01',
                'Gartenpflege',
                Money::fromEuros('1200.00'),
                'Grünwerk Gartenpflege GmbH',
                'A-2025-311',
                '2025-11-28'
            ),
            new InvoiceReference(
                'k-02',
                'Gutschrift Gartenpflege',
                Money::fromEuros('-181.00'),
                'Grünwerk Gartenpflege GmbH',
                'G-2025-004',
                '2025-12-05',
                null,
                true,
                'k-01'
            ),
            new InvoiceReference(
                'k-05',
                'Gebäudeversicherung',
                Money::fromEuros('640.00'),
                'Rheinische Versicherung AG',
                'R-2025-118',
                '2025-03-14'
            ),
            new InvoiceReference(
                'k-06',
                'Gebäudeversicherung, erneut erfasst',
                Money::fromEuros('640.00'),
                'Rheinische Versicherung AG',
                'R-2025-118',
                '2025-03-14'
            ),
        ];
    }

    /**
     * Vorjahresbeträge je Kostenart.
     *
     * @return list<PreviousYearCategoryAmount>
     */
    public static function previousYear(): array
    {
        return [
            new PreviousYearCategoryAmount('GARTENPFLEGE', 'Gartenpflege', Money::fromEuros('760.00')),
            new PreviousYearCategoryAmount('MUELL', 'Müllbeseitigung', Money::fromEuros('880.00')),
            new PreviousYearCategoryAmount('WASSER', 'Wasserversorgung', Money::fromEuros('980.00')),
            new PreviousYearCategoryAmount('VERSICHERUNG', 'Gebäudeversicherung', Money::fromEuros('620.00')),
            new PreviousYearCategoryAmount('ALLGEMEINSTROM', 'Allgemeinstrom', Money::fromEuros('300.00')),
        ];
    }
}
