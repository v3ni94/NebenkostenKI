<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ExtractedFieldStatus;
use App\Models\Document;
use App\Models\ExtractedField;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExtractedField>
 */
class ExtractedFieldFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = ExtractedField::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, Document::class, 'document_id'),
            'billing_run_id' => fn (array $attributes): string => $this->parentColumn($attributes, 'document_id'),
            'schema_key' => 'grundsteuer.jahresbetrag_cent',
            'schema_version' => '1.0.0',
            'value' => ['betrag_cent' => 43200],
            'page_number' => 1,
            'source_excerpt' => 'Jahresbetrag 432,00 EUR',
            'confidence' => '0.9100',
            'status' => ExtractedFieldStatus::AUTOMATISCH_ERKANNT,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function parentColumn(array $attributes, string $foreignKey): string
    {
        $parentId = $attributes[$foreignKey] ?? null;

        if ($parentId === null) {
            return '';
        }

        $value = Document::query()->whereKey($parentId)->value('billing_run_id');

        return is_string($value) ? $value : '';
    }

    public function lowConfidence(): static
    {
        return $this->state(fn (array $attributes): array => ['confidence' => '0.4200']);
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ExtractedFieldStatus::BESTAETIGT,
            'confirmed_at' => now(),
        ]);
    }
}
