<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmailStatus;
use Database\Factories\EmailMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Protokoll einer Transaktionsmail.
 *
 * DATENSCHUTZ: Keine Passwoerter, Tokens, Downloadlinks oder vertraulichen
 * Inhalte. Finale Mieterabrechnungen werden nicht unverschluesselt angehaengt.
 * Einzige Ausnahme ist der verschluesselte, kurzlebige Wiederholungspuffer
 * retry_payload fuer zeitweilig gescheiterte Nachrichten (MailDispatcher).
 *
 * @property string $id
 * @property string|null $organization_id
 * @property string|null $user_id
 * @property string|null $billing_run_id
 * @property string $template
 * @property string $recipient_email
 * @property string $subject
 * @property EmailStatus $status
 * @property string|null $message_id
 * @property int $attempts
 * @property Carbon|null $queued_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $retry_payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BillingRun|null $billingRun
 * @property-read Organization|null $organization
 * @property-read User|null $user
 */
class EmailMessage extends Model
{
    /** @use HasFactory<EmailMessageFactory> */
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
            'status' => EmailStatus::class,
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            // Wiederholungspuffer, kann einen zeitlich begrenzten Downloadlink
            // tragen und liegt deshalb nur verschluesselt in der Datenbank.
            'retry_payload' => 'encrypted',
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
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<BillingRun, $this>
     */
    public function billingRun(): BelongsTo
    {
        return $this->belongsTo(BillingRun::class);
    }
}
