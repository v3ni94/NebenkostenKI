<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProcessingJobStatus;
use App\Models\BillingRun;
use App\Models\ProcessingJob;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcessingJob>
 */
class ProcessingJobFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = ProcessingJob::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'billing_run_id' => BillingRun::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, BillingRun::class, 'billing_run_id'),
            'job_type' => 'dokument.extrahieren',
            'status' => ProcessingJobStatus::BEREIT,
            'priority' => 100,
            'attempts' => 0,
            'max_attempts' => 3,
            'available_at' => now(),
            'payload' => ['versuch' => 1],
        ];
    }

    public function deadLetter(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ProcessingJobStatus::DEAD_LETTER,
            'attempts' => 3,
            'error_code' => 'EXTRACTION_FAILED',
        ]);
    }
}
