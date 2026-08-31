<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\ReminderPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReminderPreference>
 */
class ReminderPreferenceFactory extends Factory
{
    protected $model = ReminderPreference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'property_id' => null,
            'is_active' => true,
            'q1_enabled' => true,
            'q2_enabled' => true,
            'q3_enabled' => true,
            'december_enabled' => true,
            'unsubscribe_token' => Str::random(48),
        ];
    }

    public function deactivated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
            'deactivated_at' => now(),
        ]);
    }
}
