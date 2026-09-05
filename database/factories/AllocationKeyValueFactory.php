<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ValueSource;
use App\Models\AllocationKey;
use App\Models\AllocationKeyValue;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AllocationKeyValue>
 */
class AllocationKeyValueFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = AllocationKeyValue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'allocation_key_id' => AllocationKey::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, AllocationKey::class, 'allocation_key_id'),
            'numerator' => '72.500000',
            'source' => ValueSource::MIETVERTRAG,
        ];
    }
}
