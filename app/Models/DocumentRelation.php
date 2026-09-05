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
use Illuminate\Support\Carbon;

/**
 * Beziehung zwischen zwei Dokumenten, zum Beispiel Dublette oder Gutschrift.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $billing_run_id
 * @property string $from_document_id
 * @property string $to_document_id
 * @property DocumentRelationType $relation_type
 *
 * Dezimalspalten sind bewusst String und werden mit brick/math gerechnet, nie als float (ADR-004).
 * @property string|null $confidence
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BillingRun $billingRun
 * @property-read Document $fromDocument
 * @property-read Organization $organization
 * @property-read Document $toDocument
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
