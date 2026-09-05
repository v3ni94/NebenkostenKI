<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleFinalizationState;
use App\Rules\Definitions\PaymentAmountMismatchRule;
use App\Rules\Definitions\RepeatedFinalizationRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Rules\Concerns\BuildsRuleContext;

/**
 * Mehrfachfinalisierung und Zahlungsbetragsabweichung.
 */
final class FinalizationRulesTest extends TestCase
{
    use BuildsRuleContext;

    #[Test]
    public function erste_finalisierung_ergibt_keinen_befund(): void
    {
        $context = $this->context(finalizationState: new RuleFinalizationState(0));

        $this->assertSame([], $this->evaluate(new RepeatedFinalizationRule, $context));
    }

    #[Test]
    public function erneute_finalisierung_ohne_freigabe_blockiert(): void
    {
        $context = $this->context(finalizationState: new RuleFinalizationState(1));

        $findings = $this->evaluate(new RepeatedFinalizationRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::BLOCKER, $findings[0]->severity);
        $this->assertStringContainsString('Freigabe', $findings[0]->description);
    }

    #[Test]
    public function freigegebene_korrektur_ergibt_einen_hinweis_auf_eine_neue_version(): void
    {
        $context = $this->context(finalizationState: new RuleFinalizationState(1, correctionApproved: true));

        $findings = $this->evaluate(new RepeatedFinalizationRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::HINWEIS, $findings[0]->severity);
        $this->assertStringContainsString('neue Version', $findings[0]->description);
    }

    #[Test]
    public function uebereinstimmender_zahlungsbetrag_ergibt_keinen_befund(): void
    {
        $context = $this->context(finalizationState: new RuleFinalizationState(
            0,
            $this->euros('74.70'),
            $this->euros('74.70'),
        ));

        $this->assertSame([], $this->evaluate(new PaymentAmountMismatchRule, $context));
    }

    #[Test]
    public function abweichender_zahlungsbetrag_blockiert(): void
    {
        $context = $this->context(finalizationState: new RuleFinalizationState(
            0,
            $this->euros('74.70'),
            $this->euros('49.80'),
        ));

        $findings = $this->evaluate(new PaymentAmountMismatchRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::BLOCKER, $findings[0]->severity);
        $this->assertSame(4980, $findings[0]->context['paidCent']);
        $this->assertStringContainsString('74,70 EUR', $findings[0]->description);
    }

    #[Test]
    public function ohne_zahlungsangaben_wird_nicht_geprueft(): void
    {
        $context = $this->context(finalizationState: new RuleFinalizationState(0, $this->euros('74.70')));

        $this->assertSame([], $this->evaluate(new PaymentAmountMismatchRule, $context));
    }
}
