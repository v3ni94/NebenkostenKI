<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GeneratedDocumentKind;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentVariant;
use Database\Factories\GeneratedDocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vom System erzeugtes Ergebnisartefakt.
 *
 * DATENSCHUTZ: Einzige Tabelle mit dauerhafter Storage-Referenz. Zulaessig sind
 * ausschliesslich erzeugte Artefakte wie Vorschau-PDFs, Final-PDFs, ZIP-Pakete,
 * HVM-Rechnungen und DSGVO-Exporte. Hochgeladene Originalbelege duerfen hier
 * niemals eingetragen werden. Der Zugriff erfolgt nur ueber autorisierte
 * Streaming-Routen oder kurzlebige signierte Links.
 */
class GeneratedDocument extends Model
{
    /** @use HasFactory<GeneratedDocumentFactory> */
    use HasFactory;

    use HasUlids;

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
            'kind' => GeneratedDocumentKind::class,
            'variant' => GeneratedDocumentVariant::class,
            'status' => GeneratedDocumentStatus::class,
            'byte_size' => 'integer',
            'page_count' => 'integer',
            'generated_at' => 'datetime',
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
     * @return BelongsTo<UnitStatement, $this>
     */
    public function unitStatement(): BelongsTo
    {
        return $this->belongsTo(UnitStatement::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<CalculationSnapshot, $this>
     */
    public function calculationSnapshot(): BelongsTo
    {
        return $this->belongsTo(CalculationSnapshot::class);
    }

    /**
     * @return BelongsTo<GeneratedDocument, $this>
     */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_document_id');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', GeneratedDocumentStatus::AKTIV->value);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeFinal(Builder $query): void
    {
        $query->where('variant', GeneratedDocumentVariant::FINAL->value);
    }
}
