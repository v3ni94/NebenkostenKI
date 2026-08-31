<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentProvider;
use App\Enums\WebhookProcessingStatus;
use App\Enums\WebhookSignatureStatus;
use Database\Factories\WebhookEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eingehendes Webhook-Event. provider_event_id ist eindeutig und sichert die
 * idempotente Verarbeitung.
 *
 * DATENSCHUTZ: Die Nutzlast wird anwendungsseitig verschluesselt und datensparsam
 * gekuerzt gespeichert. Roh-Payloads gehoeren nicht in Application Logs.
 *
 * @property string $id
 * @property PaymentProvider $provider
 * @property string $provider_event_id
 * @property string $event_type
 * @property WebhookSignatureStatus $signature_status
 * @property WebhookProcessingStatus $processing_status
 * @property string|null $payment_id
 * @property string|null $payload_digest
 * @property string|null $payload
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 * @property int $attempts
 * @property string|null $error_code
 * @property string|null $error_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Payment|null $payment
 */
class WebhookEvent extends Model
{
    /** @use HasFactory<WebhookEventFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @var list<string>
     */
    protected $hidden = ['payload'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'signature_status' => WebhookSignatureStatus::class,
            'processing_status' => WebhookProcessingStatus::class,
            'payload' => 'encrypted',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeUnprocessed(Builder $query): void
    {
        $query->where('processing_status', WebhookProcessingStatus::EMPFANGEN->value);
    }
}
