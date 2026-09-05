<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Domain\Calculation\Check\PreviousYearCategoryAmount;
use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleCostItem;
use App\Rules\Context\RuleSupplierHistory;
use App\Rules\Definitions\MissingPreviousYearCategoryRule;
use App\Rules\Definitions\PreviousYearDeviationRule;
use App\Rules\Definitions\UnusualSupplierOrAmountRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Rules\Concerns\BuildsRuleContext;

/**
 * Vorjahresvergleich, Vergessens-Check und Aufmerksamkeitshinweise.
 */
final class PreviousYearRulesTest extends TestCase
{
    use BuildsRuleContext;

    #[Test]
    public function abweichung_unter_der_grenze_ergibt_keinen_befund(): void
    {
        $context = $this->context(
            costItems: [
                new RuleCostItem('pos-1', 'Gebäudereinigung', 'KAT-REIN', 'Gebäudereinigung', $this->euros('1100.00')),
            ],
            previousYearCategories: [
                new PreviousYearCategoryAmount('KAT-REIN', 'Gebäudereinigung', $this->euros('1000.00')),
            ]
        );

        $this->assertSame([], $this->evaluate(new PreviousYearDeviationRule, $context));
    }

    #[Test]
    public function abweichung_ab_der_grenze_ergibt_eine_warnung(): void
    {
        $context = $this->context(
            costItems: [
                new RuleCostItem('pos-1', 'Gebäudereinigung', 'KAT-REIN', 'Gebäudereinigung', $this->euros('1400.00')),
            ],
            previousYearCategories: [
                new PreviousYearCategoryAmount('KAT-REIN', 'Gebäudereinigung', $this->euros('1000.00')),
            ]
        );

        $findings = $this->evaluate(new PreviousYearDeviationRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::WARNUNG, $findings[0]->severity);
        $this->assertSame(30, $findings[0]->context['thresholdPercent']);
        $this->assertStringContainsString('bestätigen', $findings[0]->description);
    }

    #[Test]
    public function ohne_vorjahresdaten_wird_nicht_verglichen(): void
    {
        $context = $this->context(costItems: [
            new RuleCostItem('pos-1', 'Gebäudereinigung', 'KAT-REIN', 'Gebäudereinigung', $this->euros('9000.00')),
        ]);

        $this->assertSame([], $this->evaluate(new PreviousYearDeviationRule, $context));
    }

    #[Test]
    public function vollstaendige_kategorien_ergeben_keinen_befund(): void
    {
        $context = $this->context(
            costItems: [
                new RuleCostItem('pos-1', 'Allgemeinstrom', 'KAT-STROM', 'Allgemeinstrom', $this->euros('420.00')),
            ],
            previousYearCategories: [
                new PreviousYearCategoryAmount('KAT-STROM', 'Allgemeinstrom', $this->euros('410.00')),
            ]
        );

        $this->assertSame([], $this->evaluate(new MissingPreviousYearCategoryRule, $context));
    }

    #[Test]
    public function fehlende_vorjahreskategorie_ergibt_eine_warnung(): void
    {
        $context = $this->context(
            costItems: [
                new RuleCostItem('pos-1', 'Allgemeinstrom', 'KAT-STROM', 'Allgemeinstrom', $this->euros('420.00')),
            ],
            previousYearCategories: [
                new PreviousYearCategoryAmount('KAT-STROM', 'Allgemeinstrom', $this->euros('410.00')),
                new PreviousYearCategoryAmount('KAT-SCHORN', 'Schornsteinreinigung', $this->euros('180.00')),
            ]
        );

        $findings = $this->evaluate(new MissingPreviousYearCategoryRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::WARNUNG, $findings[0]->severity);
        $this->assertSame('KAT-SCHORN', $findings[0]->entityId);
    }

    #[Test]
    public function bekannter_lieferant_und_normaler_betrag_ergeben_keinen_befund(): void
    {
        $context = $this->context(
            costItems: [
                new RuleCostItem('pos-1', 'Hausmeisterservice', 'KAT-HAUSWART', 'Hauswart', $this->euros('900.00'), supplier: 'Hausmeisterservice Bruhnke GmbH'),
            ],
            supplierHistory: new RuleSupplierHistory(
                ['Hausmeisterservice Bruhnke GmbH'],
                $this->euros('5000.00'),
                true
            )
        );

        $this->assertSame([], $this->evaluate(new UnusualSupplierOrAmountRule, $context));
    }

    #[Test]
    public function neuer_lieferant_und_hoher_betrag_ergeben_hinweise(): void
    {
        $context = $this->context(
            costItems: [
                new RuleCostItem('pos-1', 'Fassadenreinigung', 'KAT-REIN', 'Gebäudereinigung', $this->euros('7400.00'), supplier: 'Reinigungsdienst Sprenger e. K.'),
            ],
            supplierHistory: new RuleSupplierHistory(
                ['Hausmeisterservice Bruhnke GmbH'],
                $this->euros('5000.00'),
                true
            )
        );

        $findings = $this->evaluate(new UnusualSupplierOrAmountRule, $context);

        $this->assertCount(2, $findings);
        $this->assertSame(ValidationSeverity::HINWEIS, $findings[0]->severity);
        $this->assertStringContainsString('kam im Vorjahr nicht vor', $findings[0]->description);
        $this->assertStringContainsString('Aufmerksamkeitsschwelle', $findings[1]->description);
    }

    #[Test]
    public function ohne_vorjahreslauf_wird_kein_neuer_lieferant_gemeldet(): void
    {
        $context = $this->context(
            costItems: [
                new RuleCostItem('pos-1', 'Fassadenreinigung', 'KAT-REIN', 'Gebäudereinigung', $this->euros('400.00'), supplier: 'Reinigungsdienst Sprenger e. K.'),
            ],
            supplierHistory: new RuleSupplierHistory([], $this->euros('5000.00'), false)
        );

        $this->assertSame([], $this->evaluate(new UnusualSupplierOrAmountRule, $context));
    }
}
