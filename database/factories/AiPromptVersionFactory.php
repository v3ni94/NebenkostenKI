<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiCallPurpose;
use App\Models\AiPromptVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiPromptVersion>
 */
class AiPromptVersionFactory extends Factory
{
    protected $model = AiPromptVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $version = '1.'.fake()->unique()->numberBetween(0, 99999).'.0';

        return [
            'purpose' => AiCallPurpose::EXTRAKTION,
            'version' => $version,
            'hash' => hash('sha256', 'prompt-'.$version),
            'is_active' => true,
            'activated_at' => now(),
        ];
    }
}
