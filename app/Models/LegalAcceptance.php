<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LegalDocumentPurpose;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nachweis einer Zustimmung zu einer versionierten Textfassung.
 *
 * Gespeichert werden nur gekuerzte IP und gehashter User-Agent, niemals der
 * vollstaendige Fingerabdruck des Nutzers.
 */
class LegalAcceptance extends Model
{
    /** @use HasFactory<\Database\Factories\LegalAcceptanceFactory> */
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
