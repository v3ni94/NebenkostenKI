<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BillingRun;
use App\Models\CostItem;
use App\Models\ManualOverride;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ManualOverride>
 */
class ManualOverrideFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = ManualOverride::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'billing_run_id' => BillingRun::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, BillingRun::class, 'billing_run_id'),
            'subject_type' => CostItem::class,
            'subject_id' => (string) str()->ulid(),
            'field' => 'amount_cent',
            'old_value' => ['wert' => 128450],
            'new_value' => ['wert' => 126000],
            'reason' => 'Korrektur nach Belegpruefung durch den Vermieter',
            'occurred_at' => now(),
        ];
    }
}
