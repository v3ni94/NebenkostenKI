<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Domain\Period\DatePeriodRange;
use App\Enums\Co2ShareStatus;
use App\Enums\HeatingSupplyCase;
use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleHeatingStatement;
use App\Rules\Definitions\HeatingCaseBIncompleteRule;
use App\Rules\Definitions\HeatingCo2ShareStatusRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Rules\Concerns\BuildsRuleContext;

/**
 * Heizkostenfall B und Status der CO2-Kostenaufteilung.
 */
final class HeatingRulesTest extends TestCase
{
    use BuildsRuleContext;

    #[Test]
    public function vollstaendige_daten_im_fall_b_ergeben_keinen_befund(): void
    {
        $context = $this->context(heatingStatements: [
            new RuleHeatingStatement(
                'heiz-1',
                HeatingSupplyCase::ZENTRAL_OHNE_EXTERN,
                DatePeriodRange::calendarYear(2025),
                null,
                $this->euros('8000.00'),
                [],
                Co2ShareStatus::ENTHALTEN,
                $this->euros('2400.00'),
                $this->euros('5600.00'),
                true,
                $this->euros('900.00'),
                $this->euros('300.00'),
                $this->euros('1600.00'),
                $this->euros('420.00'),
            ),
        ]);

        $this->assertSame([], $this->evaluate(new HeatingCaseBIncompleteRule, $context));
    }

    #[Test]
    public function unvollstaendige_daten_im_fall_b_blockieren_mit_deutlichem_hinweis(): void
    {
        $context = $this->context(heatingStatements: [
            new RuleHeatingStatement(
                'heiz-1',
                HeatingSupplyCase::ZENTRAL_OHNE_EXTERN,
                DatePeriodRange::calendarYear(2025),
                null,
                $this->euros('8000.00'),
            ),
        ]);

        $findings = $this->evaluate(new HeatingCaseBIncompleteRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::BLOCKER, $findings[0]->severity);
        $this->assertStringContainsString('Heizkostenverordnung', $findings[0]->description);
        $this->assertStringContainsString('Kürzungsrecht', $findings[0]->description);
        $this->assertStringContainsString('Messdienstleister', $findings[0]->description);
        $this->assertStringContainsString('Grundkosten', $findings[0]->description);
    }

    #[Test]
    public function der_fall_b_hinweis_ist_nicht_wegklickbar(): void
    {
        $this->assertFalse((new HeatingCaseBIncompleteRule)->isUserResolvable());
    }

    #[Test]
    public function externe_abrechnung_loest_die_fall_b_regel_nicht_aus(): void
    {
        $context = $this->context(heatingStatements: [
            new RuleHeatingStatement(
                'heiz-1',
                HeatingSupplyCase::EXTERN_ABGERECHNET,
                DatePeriodRange::calendarYear(2025),
                'Wärmemess Rothbach GmbH',
                $this->euros('7200.00'),
            ),
        ]);

        $this->assertSame([], $this->evaluate(new HeatingCaseBIncompleteRule, $context));
    }

    #[Test]
    public function bekannter_co2_status_ergibt_keinen_befund(): void
    {
        $context = $this->context(heatingStatements: [
            new RuleHeatingStatement(
                'heiz-1',
                HeatingSupplyCase::EXTERN_ABGERECHNET,
                DatePeriodRange::calendarYear(2025),
                'Wärmemess Rothbach GmbH',
                $this->euros('7200.00'),
                [],
                Co2ShareStatus::NICHT_ENTHALTEN,
            ),
        ]);

        $this->assertSame([], $this->evaluate(new HeatingCo2ShareStatusRule, $context));
    }

    #[Test]
    public function unbekannter_co2_status_ergibt_eine_warnung(): void
    {
        $context = $this->context(heatingStatements: [
            new RuleHeatingStatement(
                'heiz-1',
                HeatingSupplyCase::EXTERN_ABGERECHNET,
                DatePeriodRange::calendarYear(2025),
                'Wärmemess Rothbach GmbH',
                $this->euros('7200.00'),
                [],
                Co2ShareStatus::UNBEKANNT,
            ),
        ]);

        $findings = $this->evaluate(new HeatingCo2ShareStatusRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::WARNUNG, $findings[0]->severity);
        $this->assertStringContainsString('CO2-Kosten', $findings[0]->description);
    }

    #[Test]
    public function dezentrale_versorgung_loest_die_co2_regel_nicht_aus(): void
    {
        $context = $this->context(heatingStatements: [
            new RuleHeatingStatement(
                'heiz-1',
                HeatingSupplyCase::DEZENTRAL,
                DatePeriodRange::calendarYear(2025),
                co2ShareStatus: Co2ShareStatus::UNBEKANNT,
            ),
        ]);

        $this->assertSame([], $this->evaluate(new HeatingCo2ShareStatusRule, $context));
    }
}
