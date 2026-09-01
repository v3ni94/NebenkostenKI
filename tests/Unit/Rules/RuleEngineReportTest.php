<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Domain\Period\DatePeriodRange;
use App\Enums\ApportionmentStatus;
use App\Enums\ValidationSeverity;
use App\Rules\Context\RuleCategoryChecksum;
use App\Rules\Context\RuleCostItem;
use App\Rules\Context\RuleTenancy;
use App\Rules\Context\RuleUnit;
use App\Rules\Engine\FinalizationGate;
use App\Rules\Engine\RuleEngine;
use App\Rules\Engine\Ruleset;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Rules\Concerns\BuildsRuleContext;

/**
 * Prüfbericht: Gruppierung, bestandene Pruefschritte, Sperre der
 * Finalisierung.
 */
final class RuleEngineReportTest extends TestCase
{
    use BuildsRuleContext;

    #[Test]
    public function der_pruefbericht_hat_vier_gruppen_in_fester_reihenfolge(): void
    {
        $report = (new RuleEngine)->runForContext($this->context());

        $this->assertSame(
            ['BLOCKER', 'WARNUNG', 'HINWEIS', 'BESTANDEN'],
            array_keys($report->grouped())
        );
    }

    #[Test]
    public function ein_lauf_ohne_befunde_gibt_alle_pruefschritte_als_bestanden_aus(): void
    {
        $report = (new RuleEngine)->runForContext($this->context());
        $ruleset = Ruleset::forContext($this->context());

        $this->assertSame([], $report->blockers());
        $this->assertSame([], $report->warnungen());
        $this->assertCount($ruleset->count(), $report->bestanden());
        $this->assertNotSame('', $report->bestanden()[0]->description);
    }

    #[Test]
    public function befunde_werden_in_die_richtigen_gruppen_einsortiert(): void
    {
        $context = $this->context(
            preparedOn: $this->day('2027-06-01'),
            costItems: [
                new RuleCostItem(
                    'pos-1',
                    'Verwaltervergütung',
                    'KAT-VERWALTUNG',
                    'Verwaltungskosten',
                    $this->euros('600.00'),
                    DatePeriodRange::calendarYear(2025),
                    apportionmentStatus: ApportionmentStatus::NICHT_UMLAGEFAEHIG,
                ),
            ],
            categoryChecksums: [
                new RuleCategoryChecksum('KAT-WASSER', 'Wasserversorgung', $this->euros('2400.00'), $this->euros('2900.00')),
            ]
        );

        $report = (new RuleEngine)->runForContext($context);
        $counts = $report->groupCounts();

        $this->assertSame(1, $counts['BLOCKER']);
        $this->assertSame(1, $counts['WARNUNG']);
        $this->assertGreaterThanOrEqual(1, $counts['HINWEIS']);
        $this->assertGreaterThan(0, $counts['BESTANDEN']);
        $this->assertSame('BELEGSUMME_GEGEN_KATEGORIENSUMME', $report->blockers()[0]->ruleCode);
        $this->assertSame('NICHT_UMLAGEFAEHIGE_KOSTEN', $report->warnungen()[0]->ruleCode);
    }

    #[Test]
    public function ein_blocker_verhindert_die_finalisierung(): void
    {
        $context = $this->context(
            units: [new RuleUnit('einheit-1', 'Wohnung 1')],
        );

        $report = (new RuleEngine)->runForContext($context);
        $gate = new FinalizationGate;

        $this->assertTrue($report->blocksFinalization());
        $this->assertFalse($gate->allowsByReport($report));
        $this->assertNotSame([], $gate->reasonsByReport($report));
    }

    #[Test]
    public function eine_offene_warnung_verhindert_die_finalisierung_ebenfalls(): void
    {
        $context = $this->context(costItems: [
            new RuleCostItem(
                'pos-1',
                'Verwaltervergütung',
                'KAT-VERWALTUNG',
                'Verwaltungskosten',
                $this->euros('600.00'),
                DatePeriodRange::calendarYear(2025),
                apportionmentStatus: ApportionmentStatus::NICHT_UMLAGEFAEHIG,
            ),
        ]);

        $report = (new RuleEngine)->runForContext($context);
        $gate = new FinalizationGate;

        $this->assertFalse($report->blocksFinalization());
        $this->assertFalse($gate->allowsByReport($report));
        $this->assertSame(['NICHT_UMLAGEFAEHIGE_KOSTEN'], $report->warningCodesRequiringDecision());
    }

    #[Test]
    public function ein_lauf_ohne_blocker_und_ohne_warnung_darf_finalisiert_werden(): void
    {
        $report = (new RuleEngine)->runForContext($this->context());

        $this->assertTrue((new FinalizationGate)->allowsByReport($report));
        $this->assertSame([], (new FinalizationGate)->reasonsByReport($report));
    }

    #[Test]
    public function jedes_ergebnis_traegt_regelcode_version_und_referenz(): void
    {
        $context = $this->context(tenancies: [
            new RuleTenancy(
                'miet-1',
                'einheit-1',
                'Frau Ilona Vollrath',
                DatePeriodRange::fromIso('2025-01-01', '2025-06-30'),
                hasMovedOut: true,
                hasDeliveryAddress: false,
            ),
        ]);

        $report = (new RuleEngine)->runForContext($context);
        $result = $report->blockers()[0];

        $this->assertSame('ZUSTELLANSCHRIFT_FEHLT', $result->ruleCode);
        $this->assertSame('1.0.0', $result->ruleVersion);
        $this->assertSame(ValidationSeverity::BLOCKER, $result->severity);
        $this->assertNotSame('', $result->reference);
        $this->assertSame('Tenancy', $result->entityType);
        $this->assertSame('miet-1', $result->entityId);
    }

    #[Test]
    public function die_ergebnisabbildung_enthaelt_die_spalten_der_pruefaufgabe(): void
    {
        $report = (new RuleEngine)->runForContext($this->context());
        $attributes = $report->bestanden()[0]->toIssueAttributes();

        $this->assertArrayHasKey('rule_code', $attributes);
        $this->assertArrayHasKey('rule_version', $attributes);
        $this->assertArrayHasKey('severity', $attributes);
        $this->assertArrayHasKey('blocks_finalization', $attributes);
        $this->assertArrayHasKey('description', $attributes);
        $this->assertArrayHasKey('legal_reference', $attributes);
        $this->assertFalse($attributes['blocks_finalization']);
    }
}
