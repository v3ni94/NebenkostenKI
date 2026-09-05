<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Zahlung ueber den gehosteten Checkout des Zahlungsanbieters.
 *
 * Die Finalisierung wird ausschliesslich durch einen verifizierten Webhook
 * freigeschaltet. Betrag, Waehrung und Abrechnungslauf werden vor der
 * Freischaltung serverseitig verglichen.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $billing_run_id
 * @property string|null $user_id
 * @property PaymentProvider $provider
 * @property string|null $checkout_session_id
 * @property string|null $payment_intent_id
 * @property string $idempotency_key
 * @property int $amount_cent
 * @property string $currency
 * @property int $statement_count
 * @property int|null $unit_price_gross_cent
 * @property int|null $base_price_gross_cent
 * @property PaymentStatus $status
 * @property Carbon|null $paid_at
 * @property Carbon|null $expired_at
 * @property int|null $refunded_amount_cent
 * @property Carbon|null $refunded_at
 * @property Carbon|null $dispute_opened_at
 * @property string|null $failure_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BillingRun|null $billingRun
 * @property-read Collection<int, Invoice> $invoices
 * @property-read Organization $organization
 * @property-read User|null $user
 * @property-read Collection<int, WebhookEvent> $webhookEvents
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
