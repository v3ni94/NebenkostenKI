<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\DocumentPageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Seite eines Dokuments.
 *
 * DATENSCHUTZ: Gespeichert werden ausschliesslich Seitennummer und Referenzen.
 * Kein vollstaendiger OCR-Text, kein Text-Layer, kein Seitenbild und kein
 * dauerhafter Vorschauschluessel.
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
