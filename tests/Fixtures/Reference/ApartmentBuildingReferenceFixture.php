<?php

declare(strict_types=1);

namespace Tests\Fixtures\Reference;

use App\Domain\Allocation\AllocationKey;
use App\Domain\Allocation\ConsumptionKey;
use App\Domain\Allocation\DirectAssignmentKey;
use App\Domain\Allocation\LivingAreaKey;
use App\Domain\Allocation\PersonDaysKey;
use App\Domain\Allocation\PersonDaysSegment;
use App\Domain\Allocation\UnitCountKey;
use App\Domain\Calculation\AllocabilityStatus;
use App\Domain\Calculation\Dto\CostItemInput;
use App\Domain\Calculation\Dto\OccupancyInput;
use App\Domain\Calculation\Dto\PrepaymentInput;
use App\Domain\Calculation\Dto\StatementCalculationInput;
use App\Domain\Calculation\Dto\UnitInput;
use App\Domain\Calculation\TaxBenefitCategory;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;

/**
 * REFERENZ-FIXTURE 2: Mehrfamilienhaus mit sechs Einheiten, einem
 * Mieterwechsel, einem Leerstandsmonat und zwölf umlagefähigen Kostenarten
 * plus zwei nicht umlagefähigen Positionen.
 *
 * Abrechnungszeitraum 01.01.2025 bis 31.12.2025, 365 Tage.
 *
 * EINHEITEN UND NUTZUNGSZEITRÄUME
 *   W-1  45,00 m²  mv-1  ganzes Jahr            365 Tage   2 Personen
 *   W-2  62,50 m²  mv-2  01.01. bis 30.06.      181 Tage   3 Personen
 *                  mv-3  01.07. bis 31.12.      184 Tage   1 Person
 *   W-3  62,50 m²  mv-4  ganzes Jahr            365 Tage   2 Personen
 *   W-4  78,00 m²  mv-5  01.01. bis 30.11.      334 Tage   4 Personen
 *                  leer-w4 01.12. bis 31.12.     31 Tage   Leerstand
 *   W-5  55,00 m²  mv-6  ganzes Jahr            365 Tage   1 Person
 *   W-6  82,00 m²  mv-7  ganzes Jahr            365 Tage   2 Personen
 *   Gesamtwohnfläche 385,00 m²
 *   (45,00 + 62,50 + 62,50 + 78,00 + 55,00 + 82,00 = 385,00)
 *
 * KOSTENARTEN
 *   nach Wohnfläche (Betrag / 385,00 m² = Betrag je m²)
 *   1 Grundsteuer            3.850,00 EUR → 10,00 EUR/m²
 *   2 Entwässerung           2.310,00 EUR →  6,00 EUR/m²
 *   3 Gebäudeversicherung    2.464,00 EUR →  6,40 EUR/m²
 *   4 Allgemeinstrom           924,00 EUR →  2,40 EUR/m²
 *   5 Haus- und Grundbesitzerhaftpflicht 385,00 EUR → 1,00 EUR/m²
 *   6 Gartenpflege           1.540,00 EUR →  4,00 EUR/m²
 *       § 35a haushaltsnahe Dienstleistung, Lohnanteil 1.155,00 EUR (3,00 EUR/m²)
 *   7 Straßenreinigung          462,00 EUR →  1,20 EUR/m²
 *   nach Einheiten (Betrag / 6)
 *   8 Gebäudereinigung       1.980,00 EUR → 330,00 EUR je Einheit
 *   9 Schornsteinreinigung     240,00 EUR →  40,00 EUR je Einheit
 *       § 35a Handwerkerleistung, Lohnanteil 240,00 EUR
 *   nach Personentagen (Nenner 4.618 Personentage, 0,40 EUR je Personentag)
 *  10 Müllbeseitigung        1.847,20 EUR
 *       mv-1 2×365=730, mv-2 3×181=543, mv-3 1×184=184, mv-4 2×365=730,
 *       mv-5 4×334=1.336, Leerstand 0, mv-6 1×365=365, mv-7 2×365=730
 *       730+543+184+730+1.336+0+365+730 = 4.618
 *   nach Verbrauch mit Zwischenablesung (Nenner 520,000 m³, 7,00 EUR/m³)
 *  11 Wasserversorgung       3.640,00 EUR
 *       mv-1 82, mv-2 61, mv-3 39, mv-4 74, mv-5 120, Leerstand 3,
 *       mv-6 41, mv-7 100 → 520,000 m³
 *   Direktzuordnung aus externer Heizkostenabrechnung
 *  12 Heizung                5.400,00 EUR
 *       mv-1 720,00, mv-2 610,00, mv-3 480,00, mv-4 690,00, mv-5 1.180,00,
 *       Leerstand 95,00, mv-6 540,00, mv-7 1.085,00
 *   Summe umlagefähig 25.042,20 EUR
 *
 *   nicht umlagefähig, wird ausgeschlossen
 *  13 Verwaltungskosten      1.680,00 EUR
 *  14 Reparatur Klingelanlage  890,00 EUR
 *   Summe ausgeschlossen     2.570,00 EUR
 *
 * ZEITANTEILIGE AUFTEILUNG (nur W-2 und W-4 sind betroffen)
 * Der Anteil der Einheit wird mit Nutzungstage / 365 gewichtet; die Rundung
 * auf Cent erfolgt am Ende der Kostenzeile mit dem Largest-Remainder-
 * Verfahren. Rechenweg je Kostenart in Cent:
 *
 *   Grundsteuer     W-2 62.500: ×181/365 = 30.993,1507 → 30.993
 *                                ×184/365 = 31.506,8493 → 31.507 (+1)
 *                   W-4 78.000: ×334/365 = 71.375,3425 → 71.375
 *                                ×31/365  =  6.624,6575 →  6.625 (+1)
 *   Entwässerung    W-2 37.500: 18.595,8904 → 18.596 (+1) / 18.904,1096 → 18.904
 *                   W-4 46.800: 42.825,2055 → 42.825   /  3.974,7945 →  3.975 (+1)
 *   Versicherung    W-2 40.000: 19.835,6164 → 19.836 (+1) / 20.164,3836 → 20.164
 *                   W-4 49.920: 45.680,2192 → 45.680   /  4.239,7808 →  4.240 (+1)
 *   Allgemeinstrom  W-2 15.000:  7.438,3562 →  7.438   /  7.561,6438 →  7.562 (+1)
 *                   W-4 18.720: 17.130,0822 → 17.130   /  1.589,9178 →  1.590 (+1)
 *   Haftpflicht     W-2  6.250:  3.099,3151 →  3.099   /  3.150,6849 →  3.151 (+1)
 *                   W-4  7.800:  7.137,5342 →  7.138 (+1) /   662,4658 →    662
 *   Gartenpflege    W-2 25.000: 12.397,2603 → 12.397   / 12.602,7397 → 12.603 (+1)
 *                   W-4 31.200: 28.550,1370 → 28.550   /  2.649,8630 →  2.650 (+1)
 *   Straßenreinig.  W-2  7.500:  3.719,1781 →  3.719   /  3.780,8219 →  3.781 (+1)
 *                   W-4  9.360:  8.565,0411 →  8.565   /    794,9589 →    795 (+1)
 *   Gebäudereinig.  W-2 33.000: 16.364,3836 → 16.364   / 16.635,6164 → 16.636 (+1)
 *                   W-4 33.000: 30.197,2603 → 30.197   /  2.802,7397 →  2.803 (+1)
 *   Schornsteinfeg. W-2  4.000:  1.983,5616 →  1.984 (+1) /  2.016,4384 →  2.016
 *                   W-4  4.000:  3.660,2740 →  3.660   /    339,7260 →    340 (+1)
 *   § 35a Lohnanteil Gartenpflege
 *                   W-2 18.750:  9.297,9452 →  9.298 (+1) /  9.452,0548 →  9.452
 *                   W-4 23.400: 21.412,6027 → 21.413 (+1) /  1.987,3973 →  1.987
 *
 * ERGEBNIS JE MIETVERHÄLTNIS (umlagefähige Kosten in EUR)
 *   mv-1  3.351,00   mv-2  2.398,46   mv-3  1.989,84   mv-4  3.807,50
 *   mv-5  5.105,60   mv-6  3.048,00   mv-7  4.989,00
 *   Leerstand W-4 (Eigentümer): 352,80
 *   Summe 24.689,40 + 352,80 = 25.042,20 EUR → entspricht der Kostensumme
 *
 * VORAUSZAHLUNGEN UND SALDEN
 *   mv-1 3.360,00 Ist → Guthaben      9,00
 *   mv-2 1.800,00 Ist → Nachzahlung 598,46
 *   mv-3 1.800,00 Ist → Nachzahlung 189,84
 *   mv-4 3.840,00 Ist → Guthaben     32,50
 *   mv-5 4.400,00 Soll übernommen (ausdrücklich bestätigt) → Nachzahlung 705,60
 *   mv-6 3.000,00 Ist → Nachzahlung  48,00
 *   mv-7 5.040,00 Ist → Guthaben     51,00
 *   Saldensumme: 1.449,40 EUR Nachzahlung
 *   (24.689,40 - 23.240,00 = 1.449,40)
 *
 * § 35a EStG JE MIETVERHÄLTNIS
 *   haushaltsnahe Dienstleistung (Gartenpflege):
 *     mv-1 135,00, mv-2 92,98, mv-3 94,52, mv-4 187,50, mv-5 214,13,
 *     mv-6 165,00, mv-7 246,00
 *   Handwerkerleistung (Schornsteinreinigung):
 *     mv-1 40,00, mv-2 19,84, mv-3 20,16, mv-4 40,00, mv-5 36,60,
 *     mv-6 40,00, mv-7 40,00
 */
final class ApartmentBuildingReferenceFixture
{
    public const string KEY_AREA = 'wohnflaeche';

    public const string KEY_UNITS = 'einheiten';

    public const string KEY_PERSON_DAYS = 'personentage';

    public const string KEY_CONSUMPTION = 'wasserverbrauch';

    public const string KEY_HEATING = 'heizung';

    /** Handgeprüfte umlagefähige Kosten je Mietverhältnis in Cent. */
    public const int EXPECTED_MV1_CENT = 335100;

    public const int EXPECTED_MV2_CENT = 239846;

    public const int EXPECTED_MV3_CENT = 198984;

    public const int EXPECTED_MV4_CENT = 380750;

    public const int EXPECTED_MV5_CENT = 510560;

    public const int EXPECTED_MV6_CENT = 304800;

    public const int EXPECTED_MV7_CENT = 498900;

    public const int EXPECTED_VACANCY_CENT = 35280;

    public const int EXPECTED_INCLUDED_TOTAL_CENT = 2504220;

    public const int EXPECTED_TENANT_TOTAL_CENT = 2468940;

    public const int EXPECTED_EXCLUDED_TOTAL_CENT = 257000;

    public const int EXPECTED_BALANCE_SUM_CENT = 144940;

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
            new UnitInput('W-1', 'Wohnung 1, EG links', '45.00', '45.00'),
            new UnitInput('W-2', 'Wohnung 2, EG rechts', '62.50', '62.50'),
            new UnitInput('W-3', 'Wohnung 3, 1. OG links', '62.50', '62.50'),
            new UnitInput('W-4', 'Wohnung 4, 1. OG rechts', '78.00', '78.00'),
            new UnitInput('W-5', 'Wohnung 5, 2. OG links', '55.00', '55.00'),
            new UnitInput('W-6', 'Wohnung 6, Dachgeschoss', '82.00', '82.00'),
        ];
    }

    /**
     * @return list<OccupancyInput>
     */
    public static function occupancies(): array
    {
        return [
            OccupancyInput::tenancy('mv-1', 'W-1', self::billingPeriod(), 'Mietverhältnis Wohnung 1'),
            OccupancyInput::tenancy(
                'mv-2',
                'W-2',
                DatePeriodRange::fromIso('2025-01-01', '2025-06-30'),
                'Mietverhältnis Wohnung 2 bis 30.06.2025'
            ),
            OccupancyInput::tenancy(
                'mv-3',
                'W-2',
                DatePeriodRange::fromIso('2025-07-01', '2025-12-31'),
                'Mietverhältnis Wohnung 2 ab 01.07.2025'
            ),
            OccupancyInput::tenancy('mv-4', 'W-3', self::billingPeriod(), 'Mietverhältnis Wohnung 3'),
            OccupancyInput::tenancy(
                'mv-5',
                'W-4',
                DatePeriodRange::fromIso('2025-01-01', '2025-11-30'),
                'Mietverhältnis Wohnung 4 bis 30.11.2025'
            ),
            OccupancyInput::vacancy(
                'leer-w4',
                'W-4',
                DatePeriodRange::fromIso('2025-12-01', '2025-12-31'),
                'Leerstand Wohnung 4 im Dezember 2025'
            ),
            OccupancyInput::tenancy('mv-6', 'W-5', self::billingPeriod(), 'Mietverhältnis Wohnung 5'),
            OccupancyInput::tenancy('mv-7', 'W-6', self::billingPeriod(), 'Mietverhältnis Wohnung 6'),
        ];
    }

    /**
     * @return array<string, AllocationKey>
     */
    public static function allocationKeys(): array
    {
        return [
            self::KEY_AREA => new LivingAreaKey([
                'W-1' => '45.00',
                'W-2' => '62.50',
                'W-3' => '62.50',
                'W-4' => '78.00',
                'W-5' => '55.00',
                'W-6' => '82.00',
            ]),
            self::KEY_UNITS => UnitCountKey::forUnits(['W-1', 'W-2', 'W-3', 'W-4', 'W-5', 'W-6']),
            self::KEY_PERSON_DAYS => PersonDaysKey::fromSegments([
                new PersonDaysSegment('mv-1', 2, self::billingPeriod()),
                new PersonDaysSegment('mv-2', 3, DatePeriodRange::fromIso('2025-01-01', '2025-06-30')),
                new PersonDaysSegment('mv-3', 1, DatePeriodRange::fromIso('2025-07-01', '2025-12-31')),
                new PersonDaysSegment('mv-4', 2, self::billingPeriod()),
                new PersonDaysSegment('mv-5', 4, DatePeriodRange::fromIso('2025-01-01', '2025-11-30')),
                new PersonDaysSegment('mv-6', 1, self::billingPeriod()),
                new PersonDaysSegment('mv-7', 2, self::billingPeriod()),
            ], self::billingPeriod()),
            self::KEY_CONSUMPTION => ConsumptionKey::create([
                'mv-1' => '82.000',
                'mv-2' => '61.000',
                'mv-3' => '39.000',
                'mv-4' => '74.000',
                'mv-5' => '120.000',
                'leer-w4' => '3.000',
                'mv-6' => '41.000',
                'mv-7' => '100.000',
            ], 'm³'),
            self::KEY_HEATING => DirectAssignmentKey::fromAmounts([
                'mv-1' => Money::fromEuros('720.00'),
                'mv-2' => Money::fromEuros('610.00'),
                'mv-3' => Money::fromEuros('480.00'),
                'mv-4' => Money::fromEuros('690.00'),
                'mv-5' => Money::fromEuros('1180.00'),
                'leer-w4' => Money::fromEuros('95.00'),
                'mv-6' => Money::fromEuros('540.00'),
                'mv-7' => Money::fromEuros('1085.00'),
            ]),
        ];
    }

    /**
     * @return list<CostItemInput>
     */
    public static function costItems(): array
    {
        $period = self::billingPeriod();

        return [
            self::cost('k-01', 'GRUNDSTEUER', 'Grundsteuer', '3850.00', self::KEY_AREA),
            self::cost('k-02', 'ENTWAESSERUNG', 'Entwässerung', '2310.00', self::KEY_AREA),
            self::cost('k-03', 'VERSICHERUNG', 'Gebäudeversicherung', '2464.00', self::KEY_AREA),
            self::cost('k-04', 'ALLGEMEINSTROM', 'Allgemeinstrom', '924.00', self::KEY_AREA),
            self::cost('k-05', 'HAFTPFLICHT', 'Haus- und Grundbesitzerhaftpflicht', '385.00', self::KEY_AREA),
            new CostItemInput(
                'k-06',
                'GARTENPFLEGE',
                'Gartenpflege',
                Money::fromEuros('1540.00'),
                self::KEY_AREA,
                AllocabilityStatus::ALLOCABLE,
                $period,
                null,
                TaxBenefitCategory::HOUSEHOLD_SERVICE,
                Money::fromEuros('1155.00'),
                true,
                'Rechnung Grünwerk Gartenpflege GmbH'
            ),
            self::cost('k-07', 'STRASSENREINIGUNG', 'Straßenreinigung', '462.00', self::KEY_AREA),
            self::cost('k-08', 'GEBAEUDEREINIGUNG', 'Gebäudereinigung', '1980.00', self::KEY_UNITS),
            new CostItemInput(
                'k-09',
                'SCHORNSTEINFEGER',
                'Schornsteinreinigung',
                Money::fromEuros('240.00'),
                self::KEY_UNITS,
                AllocabilityStatus::ALLOCABLE,
                $period,
                null,
                TaxBenefitCategory::CRAFTSMAN_SERVICE,
                Money::fromEuros('240.00'),
                true,
                'Rechnung Schornsteinfegermeister'
            ),
            self::cost('k-10', 'MUELL', 'Müllbeseitigung', '1847.20', self::KEY_PERSON_DAYS),
            self::cost('k-11', 'WASSER', 'Wasserversorgung', '3640.00', self::KEY_CONSUMPTION),
            self::cost('k-12', 'HEIZUNG', 'Heiz- und Warmwasserkosten', '5400.00', self::KEY_HEATING),
            new CostItemInput(
                'k-13',
                'VERWALTUNG',
                'Verwaltungskosten',
                Money::fromEuros('1680.00'),
                self::KEY_AREA,
                AllocabilityStatus::NOT_ALLOCABLE,
                $period
            ),
            new CostItemInput(
                'k-14',
                'INSTANDHALTUNG',
                'Reparatur Klingelanlage',
                Money::fromEuros('890.00'),
                self::KEY_AREA,
                AllocabilityStatus::NOT_ALLOCABLE,
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
            PrepaymentInput::actual('mv-1', Money::fromEuros('3360.00'), Money::fromEuros('3360.00'), '12 × 280,00 EUR'),
            PrepaymentInput::actual('mv-2', Money::fromEuros('1800.00'), Money::fromEuros('1800.00'), '6 × 300,00 EUR'),
            PrepaymentInput::actual('mv-3', Money::fromEuros('1800.00'), Money::fromEuros('1800.00'), '6 × 300,00 EUR'),
            PrepaymentInput::actual('mv-4', Money::fromEuros('3840.00'), Money::fromEuros('3840.00'), '12 × 320,00 EUR'),
            PrepaymentInput::assumedFromTarget('mv-5', Money::fromEuros('4400.00'), '11 × 400,00 EUR, Sollwerte bestätigt'),
            PrepaymentInput::actual('mv-6', Money::fromEuros('3000.00'), Money::fromEuros('3000.00'), '12 × 250,00 EUR'),
            PrepaymentInput::actual('mv-7', Money::fromEuros('5040.00'), Money::fromEuros('5040.00'), '12 × 420,00 EUR'),
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
            'Mehrfamilienhaus Musterstraße 7, 40789 Monheim am Rhein'
        );
    }

    private static function cost(
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
            self::billingPeriod()
        );
    }
}
