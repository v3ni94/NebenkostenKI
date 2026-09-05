<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DeletionStatus;
use App\Models\Document;
use App\Models\TemporaryUpload;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TemporaryUpload>
 */
class TemporaryUploadFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = TemporaryUpload::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory()->processing(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, Document::class, 'document_id'),
            'storage_disk' => 'temporary_uploads',
            'storage_key' => 'quarantaene/'.Str::random(40),
            'byte_size' => 348212,
            'total_chunks' => 1,
            'received_chunks' => 1,
            'received_bytes' => 348212,
            'first_chunk_at' => now(),
            'expires_at' => now()->addMinutes(120),
            'deletion_attempts' => 0,
            'is_tombstone' => false,
            'provider_deletion_status' => DeletionStatus::NICHT_ERFORDERLICH,
        ];
    }

    public function tombstone(): static
    {
        return $this->state(fn (array $attributes): array => [
            'storage_key' => null,
            'deleted_at' => now(),
            'is_tombstone' => true,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subMinutes(10),
        ]);
    }
}
