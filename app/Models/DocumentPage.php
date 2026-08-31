<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\DocumentPageFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Seite eines Dokuments.
 *
 * DATENSCHUTZ: Gespeichert werden ausschliesslich Seitennummer und Referenzen.
 * Kein vollstaendiger OCR-Text, kein Text-Layer, kein Seitenbild und kein
 * dauerhafter Vorschauschluessel.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $document_id
 * @property int $page_number
 * @property bool $has_structured_findings
 * @property int $extracted_field_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Document $document
 * @property-read Collection<int, ExtractedField> $extractedFields
 * @property-read Organization $organization
 */
class DocumentPage extends Model
{
    use BelongsToOrganization, HasUlids;

    /** @use HasFactory<DocumentPageFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'page_number' => 'integer',
            'has_structured_findings' => 'boolean',
            'extracted_field_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return HasMany<ExtractedField, $this>
     */
    public function extractedFields(): HasMany
    {
        return $this->hasMany(ExtractedField::class);
    }
}
