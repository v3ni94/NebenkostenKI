<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleAllocationKey;
use App\Rules\Context\RuleUnit;
use App\Rules\Definitions\IncompleteMeasurementRule;
use App\Rules\Definitions\InvalidDenominatorRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Rules\Concerns\BuildsRuleContext;

/**
 * Verteilungsgrundlagen: unvollstaendige Flaechen und Schluesselwerte,
 * ungueltiger Nenner, negative Werte.
 */
final class AllocationRulesTest extends TestCase
{
    use BuildsRuleContext;

    #[Test]
    public function vollstaendige_werte_ergeben_keinen_befund(): void
    {
        $context = $this->context(
            allocationKeys: [
                new RuleAllocationKey('key-1', 'Wohnfläche', 'WOHNFLAECHE', '150.00', [
                    'einheit-1' => '75.00',
                    'einheit-2' => '75.00',
                ]),
            ],
            units: [
                new RuleUnit('einheit-1', 'Wohnung 1', '75.00'),
                new RuleUnit('einheit-2', 'Wohnung 2', '75.00'),
            ]
        );

        $this->assertSame([], $this->evaluate(new IncompleteMeasurementRule, $context));
    }

    #[Test]
    public function fehlende_wohnflaeche_blockiert(): void
    {
        $context = $this->context(units: [
            new RuleUnit('einheit-1', 'Wohnung 1'),
        ]);

        $findings = $this->evaluate(new IncompleteMeasurementRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::BLOCKER, $findings[0]->severity);
        $this->assertStringContainsString('Wohnfläche', $findings[0]->description);
    }

    #[Test]
    public function fehlender_miteigentumsanteil_blockiert_wenn_er_benoetigt_wird(): void
    {
        $context = $this->context(units: [
            new RuleUnit('einheit-1', 'Wohnung 1', '75.00', requiresCoOwnershipShare: true),
        ]);

        $findings = $this->evaluate(new IncompleteMeasurementRule, $context);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('Miteigentumsanteil', $findings[0]->description);
    }

    #[Test]
    public function fehlender_zaehlerwert_im_schluessel_blockiert(): void
    {
        $context = $this->context(
            allocationKeys: [
                new RuleAllocationKey('key-1', 'Wohnfläche', 'WOHNFLAECHE', '150.00', [
                    'einheit-1' => '75.00',
                    'einheit-2' => null,
                ]),
            ],
            units: [
                new RuleUnit('einheit-1', 'Wohnung 1', '75.00'),
                new RuleUnit('einheit-2', 'Wohnung 2', '75.00'),
            ]
        );

        $findings = $this->evaluate(new IncompleteMeasurementRule, $context);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('Wohnung 2', $findings[0]->description);
        $this->assertSame('key-1', $findings[0]->entityId);
    }

    #[Test]
    public function positiver_nenner_ergibt_keinen_befund(): void
    {
        $context = $this->context(allocationKeys: [
            new RuleAllocationKey('key-1', 'Wohnfläche', 'WOHNFLAECHE', '150.00', ['einheit-1' => '150.00']),
        ]);

        $this->assertSame([], $this->evaluate(new InvalidDenominatorRule, $context));
    }

    #[Test]
    public function nenner_null_blockiert(): void
    {
        $context = $this->context(allocationKeys: [
            new RuleAllocationKey('key-1', 'Verbrauch', 'VERBRAUCH', '0.00', ['einheit-1' => '0.00']),
        ]);

        $findings = $this->evaluate(new InvalidDenominatorRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::BLOCKER, $findings[0]->severity);
        $this->assertStringContainsString('größer als null', $findings[0]->description);
    }

    #[Test]
    public function fehlender_nenner_blockiert(): void
    {
        $context = $this->context(allocationKeys: [
            new RuleAllocationKey('key-1', 'Verbrauch', 'VERBRAUCH'),
        ]);

        $findings = $this->evaluate(new InvalidDenominatorRule, $context);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('kein Gesamtnenner', $findings[0]->description);
    }

    #[Test]
    public function negativer_zaehlerwert_blockiert(): void
    {
        $context = $this->context(
            allocationKeys: [
                new RuleAllocationKey('key-1', 'Verbrauch', 'VERBRAUCH', '120.00', [
                    'einheit-1' => '130.00',
                    'einheit-2' => '-10.00',
                ]),
            ],
            units: [
                new RuleUnit('einheit-2', 'Wohnung 2', '75.00'),
            ]
        );

        $findings = $this->evaluate(new InvalidDenominatorRule, $context);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('negative Zählerwerte', $findings[0]->description);
        $this->assertStringContainsString('Wohnung 2', $findings[0]->description);
    }
}
