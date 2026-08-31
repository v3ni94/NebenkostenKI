<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GeneratedDocumentKind;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentVariant;
use App\Models\BillingRun;
use App\Models\GeneratedDocument;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GeneratedDocument>
 */
class GeneratedDocumentFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = GeneratedDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'billing_run_id' => BillingRun::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, BillingRun::class, 'billing_run_id'),
            'kind' => GeneratedDocumentKind::MIETERABRECHNUNG,
            'variant' => GeneratedDocumentVariant::VORSCHAU,
            'status' => GeneratedDocumentStatus::AKTIV,
            'storage_disk' => 'sftp',
            'storage_path' => 'artefakte/'.Str::random(24).'.pdf',
            'byte_size' => 128400,
            'sha256' => hash('sha256', 'testartefakt'),
            'page_count' => 3,
            'template_version' => '1.0.0',
            'generated_at' => now(),
        ];
    }

    public function finalVariant(): static
    {
        return $this->state(fn (array $attributes): array => [
            'variant' => GeneratedDocumentVariant::FINAL,
        ]);
    }
}
