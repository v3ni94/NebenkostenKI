<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentPage;
use Database\Factories\Concerns\ResolvesOrganizationFromParent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentPage>
 */
class DocumentPageFactory extends Factory
{
    use ResolvesOrganizationFromParent;

    protected $model = DocumentPage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'organization_id' => fn (array $attributes): string => $this->organizationFrom($attributes, Document::class, 'document_id'),
            'page_number' => 1,
            'has_structured_findings' => true,
            'extracted_field_count' => 4,
        ];
    }
}
