<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationType;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Mandant. Jeder Nutzer erhaelt bei der Registrierung mindestens eine eigene
 * Organisation, damit saemtliche Kundendaten ueber organization_id scopebar sind.
 *
 * @property string $id
 * @property string $name
 * @property OrganizationType $type
 * @property string|null $legal_form
 * @property string|null $billing_name
 * @property string|null $billing_address_line
 * @property string|null $billing_address_extra
 * @property string|null $billing_postal_code
 * @property string|null $billing_city
 * @property string $billing_country
 * @property string|null $vat_id
 * @property string|null $contact_email
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, BillingRun> $billingRuns
 * @property-read Collection<int, CostCategory> $costCategories
 * @property-read Collection<int, Invoice> $invoices
 * @property-read Collection<int, Landlord> $landlords
 * @property-read Collection<int, OrganizationUser> $memberships
 * @property-read Collection<int, Property> $properties
 * @property-read Collection<int, User> $users
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use HasUlids, SoftDeletes;

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
            'type' => OrganizationType::class,
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_user')
            ->withPivot(['role', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<OrganizationUser, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationUser::class);
    }

    /**
     * @return HasMany<Property, $this>
     */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    /**
     * @return HasMany<Landlord, $this>
     */
    public function landlords(): HasMany
    {
        return $this->hasMany(Landlord::class);
    }

    /**
     * @return HasMany<BillingRun, $this>
     */
    public function billingRuns(): HasMany
    {
        return $this->hasMany(BillingRun::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return HasMany<CostCategory, $this>
     */
    public function costCategories(): HasMany
    {
        return $this->hasMany(CostCategory::class);
    }
}
