<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AdminRole;
use App\Models\AdminRoleAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminRoleAssignment>
 */
class AdminRoleAssignmentFactory extends Factory
{
    protected $model = AdminRoleAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'role' => AdminRole::SUPPORT,
            'granted_at' => now(),
            'reason' => 'Testzuweisung im Rahmen der Entwicklung',
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes): array => ['role' => AdminRole::ADMIN]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes): array => ['revoked_at' => now()]);
    }
}
