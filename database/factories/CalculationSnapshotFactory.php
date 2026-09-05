<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CalculationSnapshotStatus;
use App\Models\BillingRun;
use App\Models\CalculationSnapshot;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalculationSnapshot>
 */
class CalculationSnapshotFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = CalculationSnapshot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $input = ['zeitraum' => ['2025-01-01', '2025-12-31'], 'einheiten' => 3];
        $result = ['summe_umlagefaehig_cent' => 480000, 'abrechnungen' => 3];

        return [
            'billing_run_id' => BillingRun::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, BillingRun::class, 'billing_run_id'),
            'version_number' => 1,
            'input' => $input,
            'result' => $result,
            'domain_version' => '1.0.0',
            'ruleset_version' => '1.0.0',
            'hash' => hash('sha256', (string) json_encode([$input, $result])),
            'status' => CalculationSnapshotStatus::BERECHNET,
            'statement_count' => 3,
            'total_apportionable_cent' => 480000,
            'total_prepayment_actual_cent' => 432000,
            'total_balance_cent' => 48000,
        ];
    }

    public function locked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CalculationSnapshotStatus::GESPERRT,
            'locked_at' => now(),
        ]);
    }
}
