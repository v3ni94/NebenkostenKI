<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationUser>
 */
class OrganizationUserFactory extends Factory
{
    protected $model = OrganizationUser::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'role' => OrganizationRole::OWNER,
            'joined_at' => now(),
        ];
    }

    public function role(OrganizationRole $role): static
    {
        return $this->state(fn (array $attributes): array => ['role' => $role]);
    }
}
