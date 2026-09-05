<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Domain\Period\DatePeriodRange;
use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleCostItem;
use App\Rules\Context\RuleTolerances;
use App\Rules\Definitions\BillingPeriodDeadlineRule;
use App\Rules\Definitions\CostOutsideBillingPeriodRule;
use App\Rules\Definitions\MissingServicePeriodRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Rules\Concerns\BuildsRuleContext;

/**
 * Zeitbezogene Pruefregeln: Abrechnungsfrist, Kosten ausserhalb des
 * Zeitraums, fehlender Leistungszeitraum.
 */
final class PeriodRulesTest extends TestCase
{
    use BuildsRuleContext;

    #[Test]
    public function frist_ohne_ueberschreitung_ergibt_keinen_befund(): void
    {
        $findings = $this->evaluate(
            new BillingPeriodDeadlineRule,
            $this->context(preparedOn: $this->day('2026-06-30'))
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function frist_ueberschreitung_ergibt_hinweis_mit_allgemeiner_information(): void
    {
        $findings = $this->evaluate(
            new BillingPeriodDeadlineRule,
            $this->context(preparedOn: $this->day('2027-04-01'))
        );

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::HINWEIS, $findings[0]->severity);
        $this->assertStringContainsString('31.12.2025', $findings[0]->description);
        $this->assertStringContainsString('gesetzlichen Abrechnungsfrist', $findings[0]->description);
        $this->assertStringContainsString('Guthaben', $findings[0]->description);
        $this->assertStringContainsString('rechtlich zu prüfen', $findings[0]->description);
    }

    #[Test]
    public function konfigurierte_monatsgrenze_wird_beachtet(): void
    {
        $findings = $this->evaluate(
            new BillingPeriodDeadlineRule,
            $this->context(
                preparedOn: $this->day('2026-06-01'),
                tolerances: new RuleTolerances(billingPeriodMonthsLimit: 3)
            )
        );

        $this->assertCount(1, $findings);
        $this->assertSame(3, $findings[0]->context['monthsLimit']);
    }

    #[Test]
    public function kosten_im_zeitraum_ergeben_keinen_befund(): void
    {
        $context = $this->context(costItems: [
            new RuleCostItem(
                'pos-1',
                'Gartenpflege',
                'KAT-GARTEN',
                'Gartenpflege',
                $this->euros('480.00'),
                DatePeriodRange::fromIso('2025-04-01', '2025-10-31'),
            ),
        ]);

        $this->assertSame([], $this->evaluate(new CostOutsideBillingPeriodRule, $context));
    }

    #[Test]
    public function kosten_vollstaendig_ausserhalb_des_zeitraums_ergeben_eine_warnung(): void
    {
        $context = $this->context(costItems: [
            new RuleCostItem(
                'pos-1',
                'Gartenpflege Vorjahr',
                'KAT-GARTEN',
                'Gartenpflege',
                $this->euros('480.00'),
                DatePeriodRange::fromIso('2024-04-01', '2024-10-31'),
            ),
        ]);

        $findings = $this->evaluate(new CostOutsideBillingPeriodRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::WARNUNG, $findings[0]->severity);
        $this->assertStringContainsString('vollständig außerhalb', $findings[0]->description);
        $this->assertSame('pos-1', $findings[0]->entityId);
    }

    #[Test]
    public function teilweise_ueberlappende_kosten_ergeben_einen_hinweis(): void
    {
        $context = $this->context(costItems: [
            new RuleCostItem(
                'pos-2',
                'Wartungsvertrag Aufzug',
                'KAT-AUFZUG',
                'Aufzug',
                $this->euros('1200.00'),
                DatePeriodRange::fromIso('2025-07-01', '2026-06-30'),
            ),
        ]);

        $findings = $this->evaluate(new CostOutsideBillingPeriodRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::HINWEIS, $findings[0]->severity);
        $this->assertSame(184, $findings[0]->context['overlappingDays']);
    }

    #[Test]
    public function vorhandener_leistungszeitraum_ergibt_keinen_befund(): void
    {
        $context = $this->context(costItems: [
            new RuleCostItem(
                'pos-1',
                'Müllbeseitigung',
                'KAT-MUELL',
                'Müllbeseitigung',
                $this->euros('960.00'),
                DatePeriodRange::calendarYear(2025),
            ),
        ]);

        $this->assertSame([], $this->evaluate(new MissingServicePeriodRule, $context));
    }

    #[Test]
    public function fehlender_leistungszeitraum_ergibt_eine_warnung_ohne_schaetzung(): void
    {
        $context = $this->context(costItems: [
            new RuleCostItem(
                'pos-1',
                'Müllbeseitigung',
                'KAT-MUELL',
                'Müllbeseitigung',
                $this->euros('960.00'),
            ),
        ]);

        $findings = $this->evaluate(new MissingServicePeriodRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::WARNUNG, $findings[0]->severity);
        $this->assertStringContainsString('kein Leistungszeitraum erfasst', $findings[0]->description);
    }
}
