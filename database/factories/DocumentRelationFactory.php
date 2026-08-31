<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DocumentRelationType;
use App\Models\Document;
use App\Models\DocumentRelation;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentRelation>
 */
class DocumentRelationFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = DocumentRelation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'from_document_id' => Document::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, Document::class, 'from_document_id'),
            'billing_run_id' => fn (array $attributes): string => $this->billingRunOf($attributes),
            'to_document_id' => fn (array $attributes): mixed => Document::factory()->create([
                'organization_id' => $attributes['organization_id'],
                'billing_run_id' => $attributes['billing_run_id'],
            ])->getKey(),
            'relation_type' => DocumentRelationType::DUBLETTE,
            'confidence' => '0.8800',
            'note' => 'Gleicher Betrag, gleiches Datum, gleicher Rechnungssteller',
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function billingRunOf(array $attributes): string
    {
        $parentId = $attributes['from_document_id'] ?? null;

        if ($parentId === null) {
            return '';
        }

        $value = Document::query()->whereKey($parentId)->value('billing_run_id');

        return is_string($value) ? $value : '';
    }
}
