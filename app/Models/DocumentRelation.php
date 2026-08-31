<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentRelationType;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\DocumentRelationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Beziehung zwischen zwei Dokumenten, zum Beispiel Dublette oder Gutschrift.
 */
class DocumentRelation extends Model
{
    use BelongsToOrganization, HasUlids;

    /** @use HasFactory<DocumentRelationFactory> */
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
            'relation_type' => DocumentRelationType::class,
            'confidence' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function fromDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'from_document_id');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function toDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'to_document_id');
    }

    /**
     * @return BelongsTo<BillingRun, $this>
     */
    public function billingRun(): BelongsTo
    {
        return $this->belongsTo(BillingRun::class);
    }
}
