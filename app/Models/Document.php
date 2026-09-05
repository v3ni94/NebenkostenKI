<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeletionStatus;
use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentType;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Dokument eines Abrechnungslaufs.
 *
 * DATENSCHUTZ: Dieses Modell enthaelt bewusst KEINEN Originaldateinamen und
 * KEINE dauerhafte Storage-Referenz auf die Originaldatei. Der temporaere
 * Storage-Key lebt ausschliesslich in TemporaryUpload und wird nach der
 * Auswertung geloescht. Dauerhaft bleiben nur die neutrale Quellenbezeichnung,
 * technische Metadaten, der HMAC-Fingerabdruck und die strukturierten
 * Extraktionsdaten.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $billing_run_id
 * @property int $sequence_number
 * @property string $source_label
 * @property DocumentType $document_type
 *
 * Dezimalspalten sind bewusst String und werden mit brick/math gerechnet, nie als float (ADR-004).
 * @property string|null $document_type_confidence
 * @property bool $type_assigned_manually
 * @property string|null $mime_type
 * @property int|null $original_byte_size
 * @property int|null $page_count
 * @property string|null $fingerprint_hmac
 * @property DocumentProcessingStatus $processing_status
 * @property Carbon|null $security_checked_at
 * @property string|null $malware_scanner_driver
 * @property bool|null $malware_scan_clean
 * @property Carbon|null $classified_at
 * @property Carbon|null $extracted_at
 * @property Carbon|null $original_deleted_at
 * @property DeletionStatus $deletion_status
 * @property string|null $duplicate_of_document_id
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, AiCall> $aiCalls
 * @property-read BillingRun $billingRun
 * @property-read Collection<int, CostItem> $costItems
 * @property-read Document|null $duplicateOf
 * @property-read Collection<int, ExtractedField> $extractedFields
 * @property-read Collection<int, DocumentRelation> $incomingRelations
 * @property-read Organization $organization
 * @property-read Collection<int, DocumentRelation> $outgoingRelations
 * @property-read Collection<int, DocumentPage> $pages
 * @property-read TemporaryUpload|null $temporaryUpload
 */
class Document extends Model
{
    use BelongsToOrganization, HasUlids;

    /** @use HasFactory<DocumentFactory> */
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
            'sequence_number' => 'integer',
            'document_type' => DocumentType::class,
            'document_type_confidence' => 'decimal:4',
            'type_assigned_manually' => 'boolean',
            'original_byte_size' => 'integer',
            'page_count' => 'integer',
            'processing_status' => DocumentProcessingStatus::class,
            'security_checked_at' => 'datetime',
            'malware_scan_clean' => 'boolean',
            'classified_at' => 'datetime',
            'extracted_at' => 'datetime',
            'original_deleted_at' => 'datetime',
            'deletion_status' => DeletionStatus::class,
        ];
    }

    /**
     * @return BelongsTo<BillingRun, $this>
     */
    public function billingRun(): BelongsTo
    {
        return $this->belongsTo(BillingRun::class);
    }

    /**
     * @return HasOne<TemporaryUpload, $this>
     */
    public function temporaryUpload(): HasOne
    {
        return $this->hasOne(TemporaryUpload::class);
    }

    /**
     * @return HasMany<DocumentPage, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(DocumentPage::class);
    }

    /**
     * @return HasMany<ExtractedField, $this>
     */
    public function extractedFields(): HasMany
    {
        return $this->hasMany(ExtractedField::class);
    }

    /**
     * @return HasMany<CostItem, $this>
     */
    public function costItems(): HasMany
    {
        return $this->hasMany(CostItem::class);
    }

    /**
     * @return HasMany<AiCall, $this>
     */
    public function aiCalls(): HasMany
    {
        return $this->hasMany(AiCall::class);
    }

    /**
     * @return HasMany<DocumentRelation, $this>
     */
    public function outgoingRelations(): HasMany
    {
        return $this->hasMany(DocumentRelation::class, 'from_document_id');
    }

    /**
     * @return HasMany<DocumentRelation, $this>
     */
    public function incomingRelations(): HasMany
    {
        return $this->hasMany(DocumentRelation::class, 'to_document_id');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_document_id');
    }

    public function isSourceDeleted(): bool
    {
        return $this->getAttribute('original_deleted_at') !== null;
    }

    /**
     * Dokumente mit offener oder fehlgeschlagener Loeschung. Grundlage des
     * Datenschutzmonitors im Adminbereich.
     *
     * @param  Builder<static>  $query
     */
    public function scopeDeletionPending(Builder $query): void
    {
        $query->whereIn('deletion_status', [
            DeletionStatus::OFFEN->value,
            DeletionStatus::IN_ARBEIT->value,
            DeletionStatus::FEHLGESCHLAGEN->value,
            DeletionStatus::UEBERFAELLIG->value,
        ]);
    }
}
