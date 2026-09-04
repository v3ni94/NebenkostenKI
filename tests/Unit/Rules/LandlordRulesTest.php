<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Enums\ValidationSeverity;
use App\Rules\Definitions\MissingLandlordRule;
use App\Rules\Engine\RuleEngine;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Rules\Concerns\BuildsRuleContext;

/**
 * Absender der Mieterabrechnung: ohne Vermieter ist die Finalisierung gesperrt.
 */
final class LandlordRulesTest extends TestCase
{
    use BuildsRuleContext;

    #[Test]
    public function ein_hinterlegter_vermieter_ergibt_keinen_befund(): void
    {
        $context = $this->context(landlordPresent: true);

        $this->assertSame([], $this->evaluate(new MissingLandlordRule, $context));
    }

    #[Test]
    public function ohne_vermieter_entsteht_ein_blocker(): void
    {
        $context = $this->context(landlordPresent: false);

        $findings = $this->evaluate(new MissingLandlordRule, $context);

        $this->assertCount(1, $findings);
        $this->assertSame(ValidationSeverity::BLOCKER, $findings[0]->severity);
        $this->assertSame('Property', $findings[0]->entityType);
        $this->assertStringContainsString('kein Vermieter hinterlegt', $findings[0]->description);
    }

    #[Test]
    public function der_fehlende_vermieter_sperrt_die_finalisierung_im_pruefbericht(): void
    {
        $report = (new RuleEngine)->runForContext($this->context(landlordPresent: false));

        $this->assertTrue($report->blocksFinalization());
        $this->assertContains(
            'VERMIETER_FEHLT',
            array_map(static fn ($result): string => $result->ruleCode, $report->blockers())
        );
    }
}
