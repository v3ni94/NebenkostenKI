<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReminderStatus;
use App\Enums\ReminderWindow;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ReminderEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Geplante oder versandte Erinnerung.
 *
 * deduplication_key ist eindeutig und verhindert Dubletten innerhalb eines
 * Erinnerungsfensters. Bereits finalisierte Jahreslaeufe erzeugen keine Erinnerung.
 *
 * @property string $id
 * @property string|null $organization_id
 * @property string|null $user_id
 * @property string|null $property_id
 * @property string|null $billing_run_id
 * @property string|null $email_message_id
 * @property int $billing_year
 * @property ReminderWindow $reminder_window
 * @property string $recipient_email
 * @property string $deduplication_key
 * @property ReminderStatus $status
 * @property Carbon $scheduled_for
 * @property Carbon|null $sent_at
 * @property string|null $suppressed_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BillingRun|null $billingRun
 * @property-read EmailMessage|null $emailMessage
 * @property-read Organization|null $organization
 * @property-read Property|null $property
 * @property-read User|null $user
 */
class ReminderEvent extends Model
{
    use BelongsToOrganization, HasUlids;

    /** @use HasFactory<ReminderEventFactory> */
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
            'billing_year' => 'integer',
            'reminder_window' => ReminderWindow::class,
            'status' => ReminderStatus::class,
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<BillingRun, $this>
     */
    public function billingRun(): BelongsTo
    {
        return $this->belongsTo(BillingRun::class);
    }

    /**
     * @return BelongsTo<EmailMessage, $this>
     */
    public function emailMessage(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class);
    }

    /**
     * Empfohlene Bildung des Deduplikationsschluessels.
     */
    public static function buildDeduplicationKey(
        string $userId,
        ?string $propertyId,
        int $billingYear,
        ReminderWindow $window
    ): string {
        return implode(':', [$userId, $propertyId ?? 'GLOBAL', (string) $billingYear, $window->value]);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->where('status', ReminderStatus::GEPLANT->value)
            ->where('scheduled_for', '<=', now());
    }
}
