<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ValidationIssueStatus;
use App\Enums\ValidationSeverity;
use App\Models\BillingRun;
use App\Models\ValidationIssue;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ValidationIssue>
 */
class ValidationIssueFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = ValidationIssue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'billing_run_id' => BillingRun::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, BillingRun::class, 'billing_run_id'),
            'rule_code' => 'BK-TEST-001',
            'rule_version' => '1.0.0',
            'severity' => ValidationSeverity::HINWEIS,
            'status' => ValidationIssueStatus::OFFEN,
            'blocks_finalization' => false,
            'title' => 'Kostenabweichung gegenueber Vorjahr',
            'description' => 'Die Kosten liegen mehr als 30 Prozent ueber dem Vorjahr. Bitte pruefen.',
            'detected_at' => now(),
        ];
    }

    public function blocker(): static
    {
        return $this->state(fn (array $attributes): array => [
            'severity' => ValidationSeverity::BLOCKER,
            'blocks_finalization' => true,
            'title' => 'Nenner des Verteilerschluessels ist null',
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ValidationIssueStatus::KORRIGIERT,
            'resolved_at' => now(),
            'resolution' => 'Wert nach Ruecksprache korrigiert',
        ]);
    }
}
