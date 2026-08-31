<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Unit;
use App\Models\VacancyPeriod;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VacancyPeriod>
 */
class VacancyPeriodFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = VacancyPeriod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'unit_id' => Unit::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, Unit::class, 'unit_id'),
            'starts_on' => '2025-07-01',
            'ends_on' => '2025-09-30',
            'reason' => 'Neuvermietung in Vorbereitung',
        ];
    }
}
