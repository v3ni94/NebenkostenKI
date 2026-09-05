<?php

declare(strict_types=1);

namespace Tests\Fixtures\Reference;

use App\Domain\Allocation\AllocationKey;
use App\Domain\Allocation\UnitCountKey;
use App\Domain\Calculation\AllocabilityStatus;
use App\Domain\Calculation\Dto\CostItemInput;
use App\Domain\Calculation\Dto\OccupancyInput;
use App\Domain\Calculation\Dto\PrepaymentInput;
use App\Domain\Calculation\Dto\StatementCalculationInput;
use App\Domain\Calculation\Dto\UnitInput;
use App\Domain\Calculation\Heating\Co2AllocationStatus;
use App\Domain\Calculation\Heating\ExternalHeatingStatementInput;
use App\Domain\Calculation\TaxBenefitCategory;
use App\Domain\Calculation\Weg\HausgeldPositionInput;
use App\Domain\Calculation\Weg\HausgeldPositionKind;
use App\Domain\Calculation\Weg\HausgeldStatementInput;
use App\Domain\Calculation\Weg\PropertyTaxInput;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;

/**
 * REFERENZ-FIXTURE 1: Eigentumswohnung mit WEG-Hausgeldabrechnung,
 * separater Grundsteuer und externer Heizkostenabrechnung.
 *
 * Alle Endbeträge sind von Hand nachgerechnet; der Rechenweg ist unten
 * vollständig offengelegt.
 *
 * AUSGANGSLAGE
 * Objekt:               Eigentumswohnung W-12, Wohnfläche 72,50 m²,
 *                       MEA 187,50 von 1.000,00
 * Abrechnungszeitraum:  01.01.2025 bis 31.12.2025 (365 Tage)
 * Mietverhältnis:       mv-2025, ganzes Jahr, Zeitfaktor 365/365 = 1
 * Heizkosten:           Fall A, externe Abrechnung ista liegt vor
 *
 * SCHRITT 1: WEG-Einzelabrechnung, Anteil der Einheit
 *
 *   übernommene, umlagefähige Positionen
 *   p-01 Wasser und Abwasser            412,80 EUR
 *   p-02 Müllbeseitigung                186,40 EUR
 *   p-03 Gebäudeversicherung            243,15 EUR
 *   p-04 Allgemeinstrom                  96,30 EUR
 *   p-05 Gartenpflege                   310,00 EUR  (§ 35a Lohnanteil 210,00 EUR)
 *   p-06 Hauswart                       480,00 EUR  (§ 35a Lohnanteil nicht ausgewiesen)
 *   p-07 Schornsteinreinigung            84,00 EUR  (§ 35a Lohnanteil 84,00 EUR)
 *   Summe übernommen                  1.812,65 EUR
 *     412,80 + 186,40 = 599,20
 *     599,20 + 243,15 = 842,35
 *     842,35 + 96,30  = 938,65
 *     938,65 + 310,00 = 1.248,65
 *   1.248,65 + 480,00 = 1.728,65
 *   1.728,65 + 84,00  = 1.812,65
 *
 *   verbindlich ausgeschlossene Positionen (Abschnitt 7.2 und 7.4)
 *   p-08 Heiz- und Warmwasserkosten   1.150,00 EUR  (nur Vergleichssumme,
 *                                                    externe Abrechnung liegt vor)
 *   p-09 Verwaltervergütung             288,00 EUR
 *   p-10 Zuführung Erhaltungsrücklage   720,00 EUR
 *   p-11 Instandsetzung Dach          1.450,00 EUR
 *   p-12 Bankgebühren                    24,00 EUR
 *   p-13 Rechtsberatung                 150,00 EUR
 *   Summe ausgeschlossen              3.782,00 EUR
 *   1.150,00 + 288,00 = 1.438,00
 *   1.438,00 + 720,00 = 2.158,00
 *   2.158,00 + 1.450,00 = 3.608,00
 *   3.608,00 + 24,00  = 3.632,00
 *   3.632,00 + 150,00 = 3.782,00
 *
 *   Prüfsumme gegen den ausgewiesenen Gesamtanteil der Einheit:
 *   1.812,65 + 3.782,00 = 5.594,65 EUR → stimmt
 *
 * SCHRITT 2: Grundsteuer
 *   Der Grundsteuerbescheid weist 385,20 EUR für 2025 aus. Die
 *   Hausgeldabrechnung enthält keine Grundsteuerposition, deshalb wird der
 *   Betrag als eigene Position übernommen (keine Dublette).
 *
 * SCHRITT 3: Externe Heizkostenabrechnung (Fall A)
 *   ista, Zeitraum 2025, Gesamtbetrag 1.148,50 EUR,
 *   Einzelbetrag der Einheit 1.148,50 EUR → Abweichung 0,00 EUR,
 *   Toleranz 5,00 EUR → Prüfsumme bestanden, Direktzuordnung freigegeben.
 *   CO2-Kostenaufteilung ist laut Abrechnung enthalten.
 *
 * SCHRITT 4: Umlagefähige Kosten des Mietverhältnisses
 *   1.812,65 (WEG) + 385,20 (Grundsteuer) + 1.148,50 (Heizung) = 3.346,35 EUR
 *   1.812,65 + 385,20 = 2.197,85
 *   2.197,85 + 1.148,50 = 3.346,35
 *   Alle Zeilen entfallen zu 100 Prozent auf die Einheit, Zeitfaktor 365/365.
 *
 * SCHRITT 5: Vorauszahlungen und Ergebnis
 *   tatsächlich geleistet: 12 × 250,00 = 3.000,00 EUR (Soll ebenfalls 3.000,00 EUR)
 *   Ergebnis: 3.346,35 - 3.000,00 = 346,35 EUR NACHZAHLUNG
 *
 * SCHRITT 6: § 35a EStG
 *   haushaltsnahe Dienstleistungen: 210,00 EUR (Gartenpflege)
 *   Handwerkerleistungen:            84,00 EUR (Schornsteinreinigung)
 *   Der Lohnanteil des Hauswarts ist nicht ausgewiesen und wird NICHT
 *   geschätzt; die Zeile wird gekennzeichnet.
 */
final class CondominiumReferenceFixture
{
    public const string UNIT_KEY = 'W-12';

    public const string TENANCY_KEY = 'mv-2025';

    public const string ALLOCATION_KEY_UNIT = 'einheit';

    public const string ALLOCATION_KEY_HEATING = 'heizung-direkt';

    /** Handgeprüfte Endbeträge in Cent. */
    public const int EXPECTED_WEG_ACCEPTED_CENT = 181265;

    public const int EXPECTED_WEG_EXCLUDED_CENT = 378200;

    public const int EXPECTED_PROPERTY_TAX_CENT = 38520;

    public const int EXPECTED_HEATING_CENT = 114850;

    public const int EXPECTED_ALLOCABLE_TOTAL_CENT = 334635;

    public const int EXPECTED_PREPAYMENT_CENT = 300000;

    public const int EXPECTED_BALANCE_CENT = 34635;

    public const int EXPECTED_TAX_BENEFIT_HOUSEHOLD_CENT = 21000;

    public const int EXPECTED_TAX_BENEFIT_CRAFTSMAN_CENT = 8400;

    public static function billingPeriod(): DatePeriodRange
    {
        return DatePeriodRange::calendarYear(2025);
    }

    public static function heatingTolerance(): Money
    {
        return Money::fromEuros('5.00');
    }

    public static function hausgeldStatement(): HausgeldStatementInput
    {
        return new HausgeldStatementInput(
            self::UNIT_KEY,
            self::billingPeriod(),
            [
                new HausgeldPositionInput('p-01', 'Wasser und Abwasser', 'WASSER', Money::fromEuros('412.80')),
                new HausgeldPositionInput('p-02', 'Müllbeseitigung', 'MUELL', Money::fromEuros('186.40')),
                new HausgeldPositionInput('p-03', 'Gebäudeversicherung', 'VERSICHERUNG', Money::fromEuros('243.15')),
                new HausgeldPositionInput('p-04', 'Allgemeinstrom', 'ALLGEMEINSTROM', Money::fromEuros('96.30')),
                new HausgeldPositionInput(
                    'p-05',
                    'Gartenpflege',
                    'GARTENPFLEGE',
                    Money::fromEuros('310.00'),
                    HausgeldPositionKind::OPERATING_COST,
                    Money::fromEuros('2480.00'),
                    true,
                    TaxBenefitCategory::HOUSEHOLD_SERVICE,
                    Money::fromEuros('210.00')
                ),
                new HausgeldPositionInput(
                    'p-06',
                    'Hauswart',
                    'HAUSWART',
                    Money::fromEuros('480.00'),
                    HausgeldPositionKind::OPERATING_COST,
                    Money::fromEuros('3840.00'),
                    true,
                    TaxBenefitCategory::HOUSEHOLD_SERVICE,
                    null,
                    false
                ),
                new HausgeldPositionInput(
                    'p-07',
                    'Schornsteinreinigung',
                    'SCHORNSTEINFEGER',
                    Money::fromEuros('84.00'),
                    HausgeldPositionKind::OPERATING_COST,
                    Money::fromEuros('672.00'),
                    true,
                    TaxBenefitCategory::CRAFTSMAN_SERVICE,
                    Money::fromEuros('84.00')
                ),
                new HausgeldPositionInput(
                    'p-08',
                    'Heiz- und Warmwasserkosten',
                    'HEIZUNG',
                    Money::fromEuros('1150.00'),
                    HausgeldPositionKind::HEATING_COST
                ),
                new HausgeldPositionInput(
                    'p-09',
                    'Verwaltervergütung',
                    'VERWALTUNG',
                    Money::fromEuros('288.00'),
                    HausgeldPositionKind::ADMINISTRATION_COST
                ),
                new HausgeldPositionInput(
                    'p-10',
                    'Zuführung Erhaltungsrücklage',
                    'RUECKLAGE',
                    Money::fromEuros('720.00'),
                    HausgeldPositionKind::RESERVE_CONTRIBUTION
                ),
                new HausgeldPositionInput(
                    'p-11',
                    'Instandsetzung Dach',
                    'INSTANDHALTUNG',
                    Money::fromEuros('1450.00'),
                    HausgeldPositionKind::MAINTENANCE_AND_REPAIR
                ),
                new HausgeldPositionInput(
                    'p-12',
                    'Bankgebühren',
                    'BANK',
                    Money::fromEuros('24.00'),
                    HausgeldPositionKind::BANK_AND_FINANCING_COST
                ),
                new HausgeldPositionInput(
                    'p-13',
                    'Rechtsberatung',
                    'RECHT',
                    Money::fromEuros('150.00'),
                    HausgeldPositionKind::LEGAL_COST
                ),
            ],
            Money::fromEuros('5594.65'),
            Money::fromEuros('4800.00'),
            Money::fromEuros('794.65'),
            'WEG Rheinpromenade 13, 40789 Monheim am Rhein'
        );
    }

    public static function propertyTax(): PropertyTaxInput
    {
        return new PropertyTaxInput(
            self::UNIT_KEY,
            Money::fromEuros('385.20'),
            self::billingPeriod(),
            true,
            false,
            'GST-2025-004711'
        );
    }

    public static function heatingStatement(): ExternalHeatingStatementInput
    {
        return new ExternalHeatingStatementInput(
            'ista',
            self::billingPeriod(),
            Money::fromEuros('1148.50'),
            [self::TENANCY_KEY => Money::fromEuros('1148.50')],
            Co2AllocationStatus::INCLUDED,
            Money::fromEuros('318.40')
        );
    }

    /**
     * @return list<UnitInput>
     */
    public static function units(): array
    {
        return [
            new UnitInput(self::UNIT_KEY, 'Wohnung 12, 2. OG rechts', '72.50', '72.50', '187.50'),
        ];
    }

    /**
     * @return list<OccupancyInput>
     */
    public static function occupancies(): array
    {
        return [
            OccupancyInput::tenancy(
                self::TENANCY_KEY,
                self::UNIT_KEY,
                self::billingPeriod(),
                'Mietverhältnis Wohnung 12'
            ),
        ];
    }

    /**
     * @return list<PrepaymentInput>
     */
    public static function prepayments(): array
    {
        return [
            PrepaymentInput::actual(
                self::TENANCY_KEY,
                Money::fromEuros('3000.00'),
                Money::fromEuros('3000.00'),
                'Zahlungsübersicht 2025, 12 Monatszahlungen von 250,00 EUR'
            ),
        ];
    }

    /**
     * Kostenposition der externen Heizkostenabrechnung.
     */
    public static function heatingCostItem(): CostItemInput
    {
        return new CostItemInput(
            'heizkosten-ista-2025',
            'HEIZUNG',
            'Heiz- und Warmwasserkosten',
            Money::fromEuros('1148.50'),
            self::ALLOCATION_KEY_HEATING,
            AllocabilityStatus::ALLOCABLE,
            self::billingPeriod(),
            null,
            TaxBenefitCategory::NONE,
            null,
            true,
            'Heizkostenabrechnung ista 2025'
        );
    }

    /**
     * @param  list<CostItemInput>  $costItems
     * @param  array<string, AllocationKey>  $allocationKeys
     */
    public static function calculationInput(array $costItems, array $allocationKeys): StatementCalculationInput
    {
        return new StatementCalculationInput(
            self::billingPeriod(),
            self::units(),
            self::occupancies(),
            $costItems,
            $allocationKeys,
            self::prepayments(),
            'Eigentumswohnung Rheinpromenade 13, Wohnung 12'
        );
    }

    /**
     * Verteilerschlüssel für die Anteile der Einheit: die Einheit trägt
     * 100 Prozent, die zeitliche Aufteilung übernimmt der Zeitfaktor.
     */
    public static function unitAllocationKey(): UnitCountKey
    {
        return UnitCountKey::forUnits([self::UNIT_KEY]);
    }
}
