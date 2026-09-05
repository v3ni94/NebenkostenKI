<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Domain\Period\DatePeriodRange;
use App\Enums\Co2ShareStatus;
use App\Enums\HeatingSupplyCase;
use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleHeatingStatement;
use App\Rules\Context\RuleUnit;
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
    public function erfasste_betraege_fuer_alle_einheiten_ergeben_keinen_befund(): void
    {
        $context = $this->context(
            heatingStatements: [
                new RuleHeatingStatement(
                    'heiz-1',
                    HeatingSupplyCase::ZENTRAL_OHNE_EXTERN,
                    DatePeriodRange::calendarYear(2025),
                    null,
                    $this->euros('8000.00'),
                    ['zeile-1' => $this->euros('5000.00'), 'zeile-2' => $this->euros('3000.00')],
                    Co2ShareStatus::ENTHALTEN,
                    manualEntry: true,
                    unitKeysWithAmounts: ['einheit-1', 'einheit-2'],
                ),
            ],
            units: [
                new RuleUnit('einheit-1', 'Wohnung 1'),
                new RuleUnit('einheit-2', 'Wohnung 2'),
            ],
        );

        $this->assertSame([], $this->evaluate(new HeatingCaseBIncompleteRule, $context));
    }

    #[Test]
    public function eine_einheit_ohne_erfasste_betraege_blockiert_mit_deutlichem_hinweis(): void
    {
        $context = $this->context(
            heatingStatements: [
                new RuleHeatingStatement(
                    'heiz-1',
                    HeatingSupplyCase::ZENTRAL_OHNE_EXTERN,
                    DatePeriodRange::calendarYear(2025),
                    null,
                    $this->euros('8000.00'),
                    ['zeile-1' => $this->euros('5000.00')],
                    Co2ShareStatus::ENTHALTEN,
                    manualEntry: true,
                    unitKeysWithAmounts: ['einheit-1'],
                ),
            ],
            units: [
                new RuleUnit('einheit-1', 'Wohnung 1'),
                new RuleUnit('einheit-2', 'Wohnung 2'),
            ],
        );

        $findings = $this->evaluate(new HeatingCaseBIncompleteRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::BLOCKER, $findings[0]->severity);
        $this->assertStringContainsString('Wohnung 2', $findings[0]->description);
        $this->assertStringNotContainsString('Wohnung 1', $findings[0]->description);
        $this->assertStringContainsString('Heizkostenverordnung', $findings[0]->description);
        $this->assertStringContainsString('Kürzungsrecht', $findings[0]->description);
        $this->assertStringContainsString('Messdienstleister', $findings[0]->description);
        $this->assertStringContainsString('nicht wegklickbar', $findings[0]->description);
    }

    #[Test]
    public function ohne_jede_erfassung_blockiert_der_fall_b(): void
    {
        $context = $this->context(
            heatingStatements: [
                new RuleHeatingStatement(
                    'heiz-1',
                    HeatingSupplyCase::ZENTRAL_OHNE_EXTERN,
                    DatePeriodRange::calendarYear(2025),
                ),
            ],
            units: [new RuleUnit('einheit-1', 'Wohnung 1')],
        );

        $findings = $this->evaluate(new HeatingCaseBIncompleteRule, $context);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('Wohnung 1', $findings[0]->description);
    }

    #[Test]
    public function ohne_bekannte_einheiten_blockiert_der_fall_b_ebenfalls(): void
    {
        $context = $this->context(heatingStatements: [
            new RuleHeatingStatement(
                'heiz-1',
                HeatingSupplyCase::ZENTRAL_OHNE_EXTERN,
                DatePeriodRange::calendarYear(2025),
            ),
        ]);

        $findings = $this->evaluate(new HeatingCaseBIncompleteRule, $context);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('alle Einheiten', $findings[0]->description);
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
