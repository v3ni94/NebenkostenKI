<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Domain\Calculation\AllocabilityStatus;
use App\Domain\Calculation\OccupancyKind;
use App\Domain\Calculation\Result\CheckCode;
use App\Domain\Calculation\Result\CheckFinding;
use App\Domain\Calculation\Result\ExcludedCost;
use App\Domain\Calculation\Result\OwnerOverviewResult;
use App\Domain\Calculation\Result\OwnerVacancyShare;
use App\Domain\Calculation\Result\ResidualShare;
use App\Domain\Calculation\Result\StatementLine;
use App\Domain\Calculation\Result\UnitStatementResult;
use App\Domain\Calculation\TaxBenefitCategory;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;
use App\Domain\Period\TimeFactor;
use App\Services\Pdf\View\BankAccount;
use App\Services\Pdf\View\DocumentIndexEntry;
use App\Services\Pdf\View\InvoiceLine;
use App\Services\Pdf\View\InvoiceView;
use App\Services\Pdf\View\LandlordSender;
use App\Services\Pdf\View\ManualDecision;
use App\Services\Pdf\View\OwnerOverviewView;
use App\Services\Pdf\View\PostalAddress;
use App\Services\Pdf\View\StatementSubject;
use App\Services\Pdf\View\TenantStatementView;
use App\Services\Pdf\View\VoucherEntry;
use DateTimeImmutable;

/**
 * Testdaten der PDF-Erzeugung.
 *
 * Ausschließlich frei erfundene Beispieldaten, keine echten personenbezogenen
 * Daten (Grundsatz 7).
 */
final class PdfFixtures
{
    public const string HEATING_CATEGORY = 'heizung';

    public static function billingPeriod(): DatePeriodRange
    {
        return DatePeriodRange::calendarYear(2025);
    }

    public static function landlord(bool $withBank = true): LandlordSender
    {
        return new LandlordSender(
            new PostalAddress(
                'Beispiel Vermietung Sonnenweg',
                null,
                'Sonnenweg 4',
                '40789',
                'Musterstadt',
            ),
            '02173 1234567',
            'vermietung@beispiel-sonnenweg.test',
            $withBank ? new BankAccount(
                'Beispiel Vermietung Sonnenweg',
                'DE02120300000000202051',
                'BYLADEM1001',
                'Beispielbank',
                'Betriebskosten 2025, Wohnung 3',
            ) : null,
        );
    }

    public static function tenant(): PostalAddress
    {
        return new PostalAddress(
            'Frau Beispielmieterin',
            'Wohnung 3, 2. Obergeschoss',
            'Rosenstraße 12',
            '40764',
            'Musterstadt',
        );
    }

    public static function subject(): StatementSubject
    {
        return new StatementSubject(
            'Wohnanlage Rosenstraße 12',
            'Rosenstraße 12, 40764 Musterstadt',
            'Wohnung 3',
            '2. Obergeschoss links',
        );
    }

    /**
     * @param  list<StatementLine>  $lines
     * @param  list<string>  $assumptions
     * @param  list<CheckFinding>  $findings
     */
    public static function statementResult(
        ?array $lines = null,
        array $assumptions = [],
        array $findings = [],
        int $prepaymentActualCent = 120000,
        bool $prepaymentAssumedFromTarget = false,
        string $tenantLabel = 'Beispielmieterin',
        string $unitLabel = 'Wohnung 3',
    ): UnitStatementResult {
        $lines ??= self::defaultLines();

        $allocable = Money::zero();

        foreach ($lines as $line) {
            $allocable = $allocable->plus($line->share);
        }

        $prepayment = Money::fromCents($prepaymentActualCent);

        $household = Money::zero();
        $craftsman = Money::zero();

        foreach ($lines as $line) {
            if ($line->taxBenefitLaborShare === null || ! $line->laborShareDisclosed) {
                continue;
            }

            if ($line->taxBenefitCategory === TaxBenefitCategory::HOUSEHOLD_SERVICE) {
                $household = $household->plus($line->taxBenefitLaborShare);
            }

            if ($line->taxBenefitCategory === TaxBenefitCategory::CRAFTSMAN_SERVICE) {
                $craftsman = $craftsman->plus($line->taxBenefitLaborShare);
            }
        }

        return new UnitStatementResult(
            'occ-1',
            'unit-3',
            $unitLabel,
            $tenantLabel,
            self::billingPeriod(),
            DatePeriodRange::fromIso('2025-01-01', '2025-12-31'),
            $lines,
            $allocable,
            Money::fromCents(120000),
            $prepayment,
            $prepaymentAssumedFromTarget,
            $allocable->minus($prepayment),
            $household,
            $craftsman,
            $assumptions,
            $findings,
        );
    }

    /**
     * @return list<StatementLine>
     */
    public static function defaultLines(): array
    {
        return [
            new StatementLine(
                'kosten-grundsteuer',
                'grundsteuer',
                'Grundsteuer',
                Money::fromCents(123456),
                'Wohnfläche',
                'Verteilung nach Wohnfläche, 72,50 m² von 480,00 m²',
                '72,50 m²',
                '480,00 m²',
                TimeFactor::applied(365, 365),
                Money::fromCents(18647),
                0,
                AllocabilityStatus::ALLOCABLE,
            ),
            new StatementLine(
                'kosten-hausmeister',
                'hausmeister',
                'Hausmeisterdienst',
                Money::fromCents(240000),
                'Wohnfläche',
                'Verteilung nach Wohnfläche, 72,50 m² von 480,00 m²',
                '72,50 m²',
                '480,00 m²',
                TimeFactor::applied(365, 365),
                Money::fromCents(36250),
                1,
                AllocabilityStatus::ALLOCABLE,
                false,
                null,
                TaxBenefitCategory::HOUSEHOLD_SERVICE,
                Money::fromCents(29000),
                true,
            ),
            new StatementLine(
                'kosten-treppenhausreinigung',
                'reinigung',
                'Treppenhausreinigung',
                Money::fromCents(96000),
                'Wohnfläche',
                'Verteilung nach Wohnfläche, 72,50 m² von 480,00 m²',
                '72,50 m²',
                '480,00 m²',
                TimeFactor::applied(365, 365),
                Money::fromCents(14500),
                0,
                AllocabilityStatus::ALLOCABLE,
                false,
                null,
                TaxBenefitCategory::HOUSEHOLD_SERVICE,
                null,
                false,
            ),
            new StatementLine(
                'kosten-wartung-heizung',
                'wartung',
                'Wartung Heizungsanlage',
                Money::fromCents(84000),
                'Wohnfläche',
                'Verteilung nach Wohnfläche, 72,50 m² von 480,00 m²',
                '72,50 m²',
                '480,00 m²',
                TimeFactor::applied(365, 365),
                Money::fromCents(12688),
                0,
                AllocabilityStatus::ALLOCABLE,
                false,
                null,
                TaxBenefitCategory::CRAFTSMAN_SERVICE,
                Money::fromCents(9500),
                true,
            ),
            new StatementLine(
                'kosten-muellabfuhr',
                'muellabfuhr',
                'Müllabfuhr',
                Money::fromCents(144000),
                'Personentage',
                'Verteilung nach Personentagen, 730 von 5.840 Personentagen',
                '730 Personentage',
                '5.840 Personentage',
                TimeFactor::includedInKey(365, 365),
                Money::fromCents(18000),
                0,
                AllocabilityStatus::ALLOCABLE,
            ),
            new StatementLine(
                'kosten-heizung',
                self::HEATING_CATEGORY,
                'Heizung und Warmwasser',
                Money::fromCents(560000),
                'Verbrauch und Grundkosten',
                'Heizkostenabrechnung, 30 Prozent Grundkosten nach Fläche, 70 Prozent nach Verbrauch',
                '1.480 kWh',
                '11.200 kWh',
                TimeFactor::includedInKey(365, 365),
                Money::fromCents(74000),
                0,
                AllocabilityStatus::ALLOCABLE,
            ),
        ];
    }

    /**
     * Kostenzeile mit bestätigter Ersatzverteilung ohne Zwischenablesung.
     */
    public static function substituteDistributionLine(): StatementLine
    {
        return new StatementLine(
            'kosten-wasser',
            'wasser',
            'Wasser und Abwasser',
            Money::fromCents(180000),
            'Verbrauch',
            'Verteilung nach Verbrauch, Ersatzverteilung nach Wohnfläche',
            '72,50 m²',
            '480,00 m²',
            TimeFactor::applied(184, 365),
            Money::fromCents(13700),
            0,
            AllocabilityStatus::ALLOCABLE,
            false,
            null,
            TaxBenefitCategory::NONE,
            null,
            true,
            true,
        );
    }

    /**
     * Viele Kostenarten, damit der Seitenumbruch geprüft werden kann.
     *
     * @return list<StatementLine>
     */
    public static function manyLines(int $count = 60): array
    {
        $lines = [];

        for ($i = 1; $i <= $count; $i++) {
            $lines[] = new StatementLine(
                'kosten-'.$i,
                'kategorie-'.$i,
                sprintf('Beispielkostenart Nummer %02d mit ausführlicher Bezeichnung', $i),
                Money::fromCents(100000 + $i),
                'Wohnfläche',
                'Verteilung nach Wohnfläche, 72,50 m² von 480,00 m², Erläuterung des Schlüssels für den Seitenumbruch',
                '72,50 m²',
                '480,00 m²',
                TimeFactor::applied(365, 365),
                Money::fromCents(1500 + $i),
                0,
                AllocabilityStatus::ALLOCABLE,
            );
        }

        return $lines;
    }

    /**
     * @return list<VoucherEntry>
     */
    public static function vouchers(): array
    {
        return [
            new VoucherEntry(1, 'Grundsteuer', 'Stadt Musterstadt', new DateTimeImmutable('2025-02-15'), Money::fromCents(123456), 'Bescheid'),
            new VoucherEntry(2, 'Hausmeisterdienst', 'Hausservice Beispiel GmbH', new DateTimeImmutable('2025-12-31'), Money::fromCents(240000), 'Jahresrechnung'),
            new VoucherEntry(3, 'Treppenhausreinigung', null, null, null, 'Rechnung'),
        ];
    }

    public static function statementView(
        ?UnitStatementResult $result = null,
        bool $showVoucherIndex = false,
        bool $showBankAccount = true,
    ): TenantStatementView {
        return new TenantStatementView(
            self::landlord(),
            self::tenant(),
            self::subject(),
            $result ?? self::statementResult(),
            new DateTimeImmutable('2026-03-31'),
            [self::HEATING_CATEGORY],
            self::vouchers(),
            $showVoucherIndex,
            $showBankAccount,
        );
    }

    public static function ownerOverviewResult(): OwnerOverviewResult
    {
        $first = self::statementResult();
        $second = self::statementResult(
            self::defaultLines(),
            [],
            [],
            200000,
            false,
            'Herr Beispielmieter',
            'Wohnung 4',
        );

        $vacancy = new OwnerVacancyShare(
            'occ-leerstand-1',
            'unit-5',
            'Wohnung 5',
            OccupancyKind::VACANCY,
            DatePeriodRange::fromIso('2025-07-01', '2025-09-30'),
            [self::defaultLines()[0]],
            Money::fromCents(45678),
        );

        $excluded = [
            new ExcludedCost(
                'kosten-verwaltung',
                'verwaltung',
                'Verwaltungskosten',
                Money::fromCents(180000),
                AllocabilityStatus::NOT_ALLOCABLE,
                'Verwaltungskosten sind nicht umlagefähig.',
            ),
            new ExcludedCost(
                'kosten-instandhaltung',
                'instandhaltung',
                'Instandhaltungsrücklage',
                Money::fromCents(240000),
                AllocabilityStatus::NOT_ALLOCABLE,
                'Zuführung zur Rücklage ist nicht umlagefähig.',
            ),
        ];

        $residual = [
            new ResidualShare(
                'kosten-grundsteuer',
                'grundsteuer',
                'Grundsteuer',
                Money::fromCents(123456),
                Money::fromCents(1234),
                'Die erfassten Miteigentumsanteile ergeben nicht den vollen Nenner.',
            ),
        ];

        $tenantTotal = $first->allocableTotal->plus($second->allocableTotal);

        return new OwnerOverviewResult(
            self::billingPeriod(),
            'Wohnanlage Rosenstraße 12',
            [$first, $second],
            [$vacancy],
            $excluded,
            $residual,
            $tenantTotal->plus($vacancy->total)->plus(Money::fromCents(1234)),
            $tenantTotal,
            $vacancy->total,
            Money::fromCents(1234),
            Money::fromCents(420000),
        );
    }

    public static function ownerOverviewView(): OwnerOverviewView
    {
        return new OwnerOverviewView(
            self::ownerOverviewResult(),
            new DateTimeImmutable('2026-03-31'),
            new PostalAddress('Beispiel Vermietung Sonnenweg', null, 'Sonnenweg 4', '40789', 'Musterstadt'),
            'Rosenstraße 12, 40764 Musterstadt',
            [
                CheckFinding::warning(CheckCode::PREVIOUS_YEAR_DEVIATION, 'Die Kosten weichen um mehr als 30 Prozent vom Vorjahr ab.'),
                CheckFinding::info(CheckCode::CHECKSUM_UNBALANCED, 'Die Prüfsumme weist eine Rundungsdifferenz aus.'),
            ],
            [
                new ManualDecision(
                    'Sammelposition sonstige Betriebskosten',
                    'Position wurde einbezogen',
                    'Vertragliche Grundlage wurde bestätigt.',
                    'Nutzerkonto Beispiel',
                    new DateTimeImmutable('2026-03-20'),
                ),
            ],
            [
                new DocumentIndexEntry(
                    'Mieterabrechnung',
                    'Finalversion',
                    'Beispielmieterin',
                    new DateTimeImmutable('2026-03-31'),
                    str_repeat('a', 64),
                    3,
                ),
            ],
            'Lauf 2025-0001',
        );
    }

    public static function invoiceView(): InvoiceView
    {
        return new InvoiceView(
            'NK-2026-000001',
            new DateTimeImmutable('2026-03-31'),
            new DateTimeImmutable('2026-03-31'),
            new PostalAddress(
                'Beispiel Vermietung Sonnenweg',
                'Herr Beispielinhaber',
                'Sonnenweg 4',
                '40789',
                'Musterstadt',
                'Deutschland',
            ),
            [
                new InvoiceLine(
                    'Erstellung Betriebskostenabrechnung Wohnanlage Rosenstraße 12, 01.01.2025 bis 31.12.2025',
                    4,
                    Money::fromCents(2092),
                    Money::fromCents(8368),
                ),
            ],
            Money::fromCents(8368),
            Money::fromCents(1592),
            Money::fromCents(9960),
            '19.00',
            'Kreditkarte über Stripe',
            'pi_beispiel_1234567890',
        );
    }
}
