<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MeterReadingKind;
use App\Enums\ValueSource;
use App\Models\MeterDevice;
use App\Models\MeterReading;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeterReading>
 */
class MeterReadingFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = MeterReading::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'meter_device_id' => MeterDevice::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, MeterDevice::class, 'meter_device_id'),
            'read_on' => '2025-12-31',
            'value' => '1234.5678',
            'reading_kind' => MeterReadingKind::ENDSTAND,
            'source' => ValueSource::ABLESEPROTOKOLL,
            'is_estimated' => false,
            'confidence' => '0.9500',
        ];
    }

    public function estimated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_estimated' => true,
            'confirmed_at' => now(),
        ]);
    }
}
