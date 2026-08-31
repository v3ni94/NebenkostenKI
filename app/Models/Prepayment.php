<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PrepaymentKind;
use App\Enums\ValueSource;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\PrepaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Vorauszahlung je Mietverhaeltnis und Zeitraum.
 *
 * In der Abrechnung wird ausschliesslich actual_cent abgezogen. target_cent dient
 * der Plausibilisierung. Die Annahme Ist gleich Soll ist nur mit
 * assumed_equal_to_target und sichtbarer Bestaetigung zulaessig.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $billing_run_id
 * @property string $tenancy_id
 * @property string|null $document_id
 * @property PrepaymentKind $kind
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property int $target_cent
 * @property int|null $actual_cent
 * @property ValueSource $source
 * @property bool $assumed_equal_to_target
 * @property string|null $confirmed_by_user_id
 * @property Carbon|null $confirmed_at
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BillingRun $billingRun
 * @property-read User|null $confirmedBy
 * @property-read Document|null $document
 * @property-read Organization $organization
 * @property-read Tenancy $tenancy
 */
class Prepayment extends Model
{
    use BelongsToOrganization, HasUlids;

    /** @use HasFactory<PrepaymentFactory> */
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
            'kind' => PrepaymentKind::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'target_cent' => 'integer',
            'actual_cent' => 'integer',
            'source' => ValueSource::class,
            'assumed_equal_to_target' => 'boolean',
            'confirmed_at' => 'datetime',
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
     * @return BelongsTo<Tenancy, $this>
     */
    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    /**
     * Vorauszahlungen ohne Istwert erzeugen eine Pruefaufgabe.
     *
     * @param  Builder<static>  $query
     */
    public function scopeMissingActual(Builder $query): void
    {
        $query->whereNull('actual_cent')->where('assumed_equal_to_target', false);
    }
}
