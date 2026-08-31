<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiCallPurpose;
use App\Enums\AiCallStatus;
use App\Enums\AiProvider;
use App\Models\AiCall;
use App\Models\BillingRun;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Enthaelt bewusst keine Prompts und keine Antworten.
 *
 * @extends Factory<AiCall>
 */
class AiCallFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = AiCall::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'billing_run_id' => BillingRun::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, BillingRun::class, 'billing_run_id'),
            'provider' => AiProvider::ANTHROPIC,
            'model' => 'testmodell-extract',
            'purpose' => AiCallPurpose::EXTRAKTION,
            'request_id' => 'req_'.Str::random(24),
            'input_tokens' => 4200,
            'output_tokens' => 850,
            'file_count' => 1,
            'cost_cent' => 3,
            'duration_ms' => 4120,
            'status' => AiCallStatus::ERFOLGREICH,
            'schema_valid' => true,
            'attempt' => 1,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AiCallStatus::SCHEMA_FEHLER,
            'schema_valid' => false,
            'error_code' => 'SCHEMA_INVALID',
        ]);
    }
}
