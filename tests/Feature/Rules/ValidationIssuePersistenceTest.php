<?php

declare(strict_types=1);

namespace Tests\Feature\Rules;

use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;
use App\Enums\ApportionmentStatus;
use App\Enums\ValidationIssueStatus;
use App\Enums\ValidationSeverity;
use App\Models\BillingRun;
use App\Models\Unit;
use App\Models\User;
use App\Models\ValidationIssue;
use App\Rules\Context\RuleContext;
use App\Rules\Context\RuleCostItem;
use App\Rules\Context\RuleTolerances;
use App\Rules\Context\RuleUnit;
use App\Rules\Engine\FinalizationGate;
use App\Rules\Engine\RuleEngine;
use App\Rules\Engine\RuleNotUserResolvableException;
use App\Rules\Engine\ValidationIssueWriter;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Persistenz der Pruefergebnisse und Protokoll der Nutzerentscheidung.
 *
 * Die Regel-Engine schreibt nicht selbst; das uebernimmt der
 * ValidationIssueWriter im Auftrag der Anwendungsschicht.
 */
final class ValidationIssuePersistenceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function der_pruefbericht_wird_als_pruefaufgaben_gespeichert(): void
    {
        $billingRun = BillingRun::factory()->create();
        $report = (new RuleEngine)->runForContext($this->contextWithWarning());

        $written = (new ValidationIssueWriter)->persist($billingRun, $report);

        $this->assertCount(count($report->results), $written);
        $this->assertSame(
            count($report->results),
            ValidationIssue::query()->where('billing_run_id', $billingRun->getKey())->count()
        );

        $warning = ValidationIssue::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('severity', ValidationSeverity::WARNUNG->value)
            ->firstOrFail();

        $this->assertSame('NICHT_UMLAGEFAEHIGE_KOSTEN', $warning->rule_code);
        $this->assertSame(ValidationIssueStatus::OFFEN, $warning->status);
        $this->assertFalse($warning->blocks_finalization);
        $this->assertNotSame('', $warning->description);
        $this->assertNotNull($warning->legal_reference);
    }

    #[Test]
    public function bestandene_pruefschritte_werden_ebenfalls_gespeichert(): void
    {
        $billingRun = BillingRun::factory()->create();
        $report = (new RuleEngine)->runForContext($this->contextWithWarning());

        (new ValidationIssueWriter)->persist($billingRun, $report);

        $this->assertGreaterThan(
            0,
            ValidationIssue::query()
                ->where('billing_run_id', $billingRun->getKey())
                ->where('severity', ValidationSeverity::BESTANDEN->value)
                ->count()
        );
    }

    #[Test]
    public function ein_offener_blocker_verhindert_die_finalisierung(): void
    {
        $billingRun = BillingRun::factory()->create();
        $unit = Unit::factory()->create([
            'property_id' => $billingRun->property_id,
            'organization_id' => $billingRun->organization_id,
            'living_area_sqm' => null,
        ]);

        $report = (new RuleEngine)->runForContext($this->contextWithBlocker((string) $unit->getKey()));
        (new ValidationIssueWriter)->persist($billingRun, $report);

        $gate = new FinalizationGate;

        $this->assertFalse($gate->allowsBillingRun($billingRun));
        $this->assertNotSame([], $gate->blockingIssues($billingRun));
    }

    #[Test]
    public function eine_warnung_ist_mit_ausdruecklicher_entscheidung_aufloesbar_und_wird_protokolliert(): void
    {
        $billingRun = BillingRun::factory()->create();
        $user = User::factory()->create();
        $report = (new RuleEngine)->runForContext($this->contextWithWarning());
        (new ValidationIssueWriter)->persist($billingRun, $report);

        $issue = ValidationIssue::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('severity', ValidationSeverity::WARNUNG->value)
            ->firstOrFail();

        $resolved = (new ValidationIssueWriter)->resolveWithDecision(
            $issue,
            $user,
            'Die Position ist vertraglich abgegrenzt und wird bewusst umgelegt.'
        );

        $this->assertSame(ValidationIssueStatus::AKZEPTIERT, $resolved->status);
        $this->assertSame((string) $user->getKey(), $resolved->resolved_by_user_id);
        $this->assertNotNull($resolved->resolved_at);
        $this->assertStringContainsString('bewusst umgelegt', (string) $resolved->resolution);
        $this->assertTrue((new FinalizationGate)->allowsBillingRun($billingRun));
    }

    #[Test]
    public function ein_blocker_kann_nicht_durch_eine_nutzerentscheidung_aufgeloest_werden(): void
    {
        $billingRun = BillingRun::factory()->create();
        $user = User::factory()->create();

        $issue = ValidationIssue::factory()->create([
            'billing_run_id' => $billingRun->getKey(),
            'organization_id' => $billingRun->organization_id,
            'rule_code' => 'HEIZKOSTEN_FALL_B_UNVOLLSTAENDIG',
            'rule_version' => '1.0.0',
            'severity' => ValidationSeverity::BLOCKER,
            'status' => ValidationIssueStatus::OFFEN,
            'blocks_finalization' => true,
        ]);

        $this->expectException(RuleNotUserResolvableException::class);

        (new ValidationIssueWriter)->resolveWithDecision($issue, $user, 'Bitte trotzdem freigeben');
    }

    #[Test]
    public function entschiedene_pruefaufgaben_bleiben_bei_einem_erneuten_lauf_erhalten(): void
    {
        $billingRun = BillingRun::factory()->create();
        $user = User::factory()->create();
        $writer = new ValidationIssueWriter;
        $report = (new RuleEngine)->runForContext($this->contextWithWarning());

        $writer->persist($billingRun, $report);

        $issue = ValidationIssue::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('severity', ValidationSeverity::WARNUNG->value)
            ->firstOrFail();

        $writer->resolveWithDecision($issue, $user, 'Bewusst umgelegt, Begründung liegt vor.');
        $writer->persist($billingRun, (new RuleEngine)->runForContext($this->contextWithWarning()));

        $this->assertSame(
            1,
            ValidationIssue::query()
                ->where('billing_run_id', $billingRun->getKey())
                ->where('rule_code', 'NICHT_UMLAGEFAEHIGE_KOSTEN')
                ->count()
        );
        $this->assertSame(
            ValidationIssueStatus::AKZEPTIERT,
            $issue->refresh()->status
        );
    }

    #[Test]
    public function eine_korrektur_wird_mit_nutzer_und_notiz_protokolliert(): void
    {
        $billingRun = BillingRun::factory()->create();
        $user = User::factory()->create();
        $report = (new RuleEngine)->runForContext($this->contextWithWarning());
        (new ValidationIssueWriter)->persist($billingRun, $report);

        $issue = ValidationIssue::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('severity', ValidationSeverity::WARNUNG->value)
            ->firstOrFail();

        $corrected = (new ValidationIssueWriter)->markCorrected($issue, $user, 'Position aus der Umlage entfernt.');

        $this->assertSame(ValidationIssueStatus::KORRIGIERT, $corrected->status);
        $this->assertSame('Position aus der Umlage entfernt.', $corrected->resolution);
    }

    private function contextWithWarning(): RuleContext
    {
        return new RuleContext(
            'lauf-2025',
            DatePeriodRange::calendarYear(2025),
            new DateTimeImmutable('2026-03-01 00:00:00', new DateTimeZone('UTC')),
            new RuleTolerances,
            [
                new RuleCostItem(
                    '01JBQ9Z5J0000000000000000A',
                    'Verwaltervergütung',
                    'KAT-VERWALTUNG',
                    'Verwaltungskosten',
                    Money::fromEuros('600.00'),
                    DatePeriodRange::calendarYear(2025),
                    apportionmentStatus: ApportionmentStatus::NICHT_UMLAGEFAEHIG,
                ),
            ],
        );
    }

    private function contextWithBlocker(string $unitKey): RuleContext
    {
        return new RuleContext(
            'lauf-2025',
            DatePeriodRange::calendarYear(2025),
            new DateTimeImmutable('2026-03-01 00:00:00', new DateTimeZone('UTC')),
            new RuleTolerances,
            units: [new RuleUnit($unitKey, 'Wohnung 1')],
        );
    }
}
