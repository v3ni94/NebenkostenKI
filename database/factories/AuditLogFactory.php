<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AdminRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_user_id' => User::factory(),
            'action' => 'abrechnungslauf.aktualisiert',
            'subject_type' => 'App\Models\BillingRun',
            'subject_id' => (string) str()->ulid(),
            'occurred_at' => now(),
            'ip_truncated' => '203.0.113.0',
            'user_agent_hash' => hash('sha256', 'testbrowser'),
            'metadata' => ['felder' => 2],
        ];
    }

    public function supportAccess(): static
    {
        return $this->state(fn (array $attributes): array => [
            'actor_admin_role' => AdminRole::SUPPORT,
            'action' => 'support.einsicht',
            'reason' => 'Ticket 4711, Nutzer bat um Pruefung der Vorschau',
        ]);
    }
}
