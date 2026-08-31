<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Zahlung ueber den gehosteten Checkout des Zahlungsanbieters.
 *
 * Die Finalisierung wird ausschliesslich durch einen verifizierten Webhook
 * freigeschaltet. Betrag, Waehrung und Abrechnungslauf werden vor der
 * Freischaltung serverseitig verglichen.
 */
class Payment extends Model
{
    use BelongsToOrganization, HasUlids;

    /** @use HasFactory<PaymentFactory> */
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
            'provider' => PaymentProvider::class,
            'amount_cent' => 'integer',
            'statement_count' => 'integer',
            'unit_price_gross_cent' => 'integer',
            'base_price_gross_cent' => 'integer',
            'status' => PaymentStatus::class,
            'paid_at' => 'datetime',
            'expired_at' => 'datetime',
            'refunded_amount_cent' => 'integer',
            'refunded_at' => 'datetime',
            'dispute_opened_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<WebhookEvent, $this>
     */
    public function webhookEvents(): HasMany
    {
        return $this->hasMany(WebhookEvent::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeSucceeded(Builder $query): void
    {
        $query->where('status', PaymentStatus::BEZAHLT->value);
    }
}
