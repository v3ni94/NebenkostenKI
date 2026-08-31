<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BillingRun;
use App\Models\BillingRunVersion;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingRunVersion>
 */
class BillingRunVersionFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = BillingRunVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $payload = ['schritt' => 6, 'bestaetigte_positionen' => 12];

        return [
            'billing_run_id' => BillingRun::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, BillingRun::class, 'billing_run_id'),
            'version_number' => 1,
            'payload' => $payload,
            'payload_hash' => hash('sha256', (string) json_encode($payload)),
            'reason' => 'Bestaetigung der Kostenpruefung',
            'created_at' => now(),
        ];
    }
}
