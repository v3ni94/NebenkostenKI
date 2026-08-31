<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LegalDocumentPurpose;
use App\Models\LegalAcceptance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalAcceptance>
 */
class LegalAcceptanceFactory extends Factory
{
    protected $model = LegalAcceptance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'purpose' => LegalDocumentPurpose::AGB,
            'document_version' => '2026-01',
            'document_hash' => hash('sha256', 'testfassung-agb-2026-01'),
            'accepted_at' => now(),
            'ip_truncated' => '203.0.113.0',
            'user_agent_hash' => hash('sha256', 'testbrowser'),
        ];
    }
}
