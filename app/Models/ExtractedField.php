<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExtractedFieldStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ExtractedFieldFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Strukturierte Extraktionsdaten, im UI ausgelesene Inhaltsdaten.
 *
 * DATENSCHUTZ: source_excerpt darf nur einen minimalen Fundstellenausschnitt
 * enthalten, der fuer das konkrete Feld erforderlich ist. Ganze Absaetze, Seiten
 * oder Tabellen sind unzulaessig. Fehlende Werte bleiben null und erzeugen eine
 * Pruefaufgabe, sie werden niemals geschaetzt.
 */
class ExtractedField extends Model
{
    use BelongsToOrganization, HasUlids;

    /** @use HasFactory<ExtractedFieldFactory> */
    use HasFactory;

    /**
     * Ab dieser Konfidenz ist keine gesonderte Einzelpruefung erforderlich. Der
     * Schwellenwert ist administrativ konfigurierbar.
     */
    public const DEFAULT_REVIEW_THRESHOLD = '0.8000';

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
            'value' => 'array',
            'corrected_value' => 'array',
            'page_number' => 'integer',
            'confidence' => 'decimal:4',
            'status' => ExtractedFieldStatus::class,
            'confirmed_at' => 'datetime',
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
     * @return BelongsTo<DocumentPage, $this>
     */
    public function documentPage(): BelongsTo
    {
        return $this->belongsTo(DocumentPage::class);
    }

    /**
     * @return BelongsTo<BillingRun, $this>
     */
    public function billingRun(): BelongsTo
    {
        return $this->belongsTo(BillingRun::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    /**
     * Felder unterhalb der Konfidenzschwelle erfordern eine explizite Pruefung.
     *
     * @param  Builder<static>  $query
     */
    public function scopeNeedsReview(Builder $query, string $threshold = self::DEFAULT_REVIEW_THRESHOLD): void
    {
        $query->where('status', ExtractedFieldStatus::AUTOMATISCH_ERKANNT->value)
            ->where(function (Builder $inner) use ($threshold): void {
                $inner->whereNull('confidence')->orWhere('confidence', '<', $threshold);
            });
    }
}
