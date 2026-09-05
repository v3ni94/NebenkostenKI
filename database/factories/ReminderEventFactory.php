<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReminderStatus;
use App\Enums\ReminderWindow;
use App\Models\Organization;
use App\Models\ReminderEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReminderEvent>
 */
class ReminderEventFactory extends Factory
{
    protected $model = ReminderEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'property_id' => null,
            'billing_year' => 2025,
            'reminder_window' => ReminderWindow::Q1,
            'recipient_email' => 'erinnerung.'.fake()->unique()->numberBetween(1000, 999999).'@beispiel.invalid',
            'deduplication_key' => (string) Str::ulid(),
            'status' => ReminderStatus::GEPLANT,
            'scheduled_for' => '2026-01-15 08:00:00',
        ];
    }

    public function sent(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ReminderStatus::GESENDET,
            'sent_at' => now(),
        ]);
    }
}
