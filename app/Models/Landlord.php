<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\LandlordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Vermieter beziehungsweise Eigentuemer als Absender der Betriebskostenabrechnung.
 *
 * Absender und inhaltlich Verantwortlicher ist immer der Vermieter, nicht die
 * Hausverwaltung Mueller GmbH. IBAN und BIC werden anwendungsseitig
 * verschluesselt gespeichert und erscheinen nur auf ausdruecklichen Wunsch.
 */
class Landlord extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    /** @use HasFactory<LandlordFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @var list<string>
     */
    protected $hidden = ['iban', 'bic'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'iban' => 'encrypted',
            'bic' => 'encrypted',
            'show_bank_details_on_statement' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Property, $this>
     */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    /**
     * @return HasMany<BillingRun, $this>
     */
    public function billingRuns(): HasMany
    {
        return $this->hasMany(BillingRun::class);
    }
}
