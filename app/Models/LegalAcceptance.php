<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LegalDocumentPurpose;
use Database\Factories\LegalAcceptanceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Nachweis einer Zustimmung zu einer versionierten Textfassung.
 *
 * Gespeichert werden nur gekuerzte IP und gehashter User-Agent, niemals der
 * vollstaendige Fingerabdruck des Nutzers.
 *
 * @property string $id
 * @property string|null $user_id
 * @property string|null $organization_id
 * @property string|null $billing_run_id
 * @property LegalDocumentPurpose $purpose
 * @property string $document_version
 * @property string|null $document_hash
 * @property Carbon $accepted_at
 * @property string|null $ip_truncated
 * @property string|null $user_agent_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BillingRun|null $billingRun
 * @property-read Organization|null $organization
 * @property-read User|null $user
 */
class LegalAcceptance extends Model
{
    /** @use HasFactory<LegalAcceptanceFactory> */
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
            'purpose' => LegalDocumentPurpose::class,
            'accepted_at' => 'datetime',
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
