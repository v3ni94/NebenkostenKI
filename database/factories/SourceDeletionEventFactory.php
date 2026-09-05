<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DeletionStatus;
use App\Models\Document;
use App\Models\SourceDeletionEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourceDeletionEvent>
 */
class SourceDeletionEventFactory extends Factory
{
    protected $model = SourceDeletionEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'organization_id' => null,
            'local_deletion_status' => DeletionStatus::ERFOLGREICH,
            'provider_deletion_status' => DeletionStatus::NICHT_ERFORDERLICH,
            'occurred_at' => now(),
            'attempt' => 1,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'local_deletion_status' => DeletionStatus::FEHLGESCHLAGEN,
            'attempt' => 3,
            'error_code' => 'STORAGE_UNAVAILABLE',
            'error_message' => 'Temporaerer Bereich nicht erreichbar',
        ]);
    }
}
