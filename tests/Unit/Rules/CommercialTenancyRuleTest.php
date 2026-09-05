<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Domain\Period\DatePeriodRange;
use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleTenancy;
use App\Rules\Context\RuleUnit;
use App\Rules\Definitions\CommercialTenancyRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Rules\Concerns\BuildsRuleContext;

/**
 * Gewerbliche Mietverhaeltnisse blockieren die automatische Finalisierung.
 */
final class CommercialTenancyRuleTest extends TestCase
{
    use BuildsRuleContext;

    #[Test]
    public function wohnraum_ergibt_keinen_befund(): void
    {
        $context = $this->context(
            tenancies: [
                new RuleTenancy('miet-1', 'einheit-1', 'Mietpartei Beispiel', DatePeriodRange::calendarYear(2025)),
            ]
        );

        $this->assertSame([], $this->evaluate(new CommercialTenancyRule, $context));
    }

    #[Test]
    public function gewerbe_ergibt_einen_nicht_aufloesbaren_blocker(): void
    {
        $rule = new CommercialTenancyRule;

        $context = $this->context(
            units: [new RuleUnit('einheit-1', 'Ladenlokal EG')],
            tenancies: [
                new RuleTenancy(
                    'miet-1',
                    'einheit-1',
                    'Buchhandlung Beispiel',
                    DatePeriodRange::calendarYear(2025),
                    isCommercial: true,
                ),
            ]
        );

        $findings = $this->evaluate($rule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::BLOCKER, $findings[0]->severity);
        $this->assertSame('miet-1', $findings[0]->entityId);
        $this->assertStringContainsString('Buchhandlung Beispiel', $findings[0]->description);
        $this->assertStringContainsString('Ladenlokal EG', $findings[0]->description);
        $this->assertStringContainsString('nicht abgerechnet', $findings[0]->description);
        $this->assertFalse($rule->isUserResolvable());
        $this->assertSame(ValidationSeverity::BLOCKER, $rule->severity());
    }
}
