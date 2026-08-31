<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mandantenbindung ueber die Spalte organization_id.
 *
 * VERBINDLICHE REGEL: Jede Query auf ein Modell mit diesem Trait ist auf den
 * Mandanten zu scopen, entweder ueber forUser() oder ueber forOrganization().
 * Es gibt bewusst keinen globalen Scope, weil Systemjobs, Adminfunktionen und
 * die Loeschueberwachung mandantenuebergreifend arbeiten muessen. Das Scoping
 * ist damit eine ausdrueckliche Entscheidung an jeder Aufrufstelle.
 *
 * Das Scoping allein genuegt nicht. Die Autorisierung laeuft zusaetzlich ueber
 * die Policies in app/Policies mit Object-Level-Check. Eine Entitaet darf
 * niemals allein aufgrund einer erratbaren URL-ID freigegeben werden.
 */
trait BelongsToOrganization
{
    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Beschraenkt die Query auf eine Organisation.
     *
     * @param  Builder<static>  $query
     */
    public function scopeForOrganization(Builder $query, Organization|string $organization): void
    {
        $query->where(
            $this->qualifyColumn('organization_id'),
            $organization instanceof Organization ? $organization->getKey() : $organization
        );
    }

    /**
     * Beschraenkt die Query auf alle Organisationen des Nutzers.
     *
     * @param  Builder<static>  $query
     */
    public function scopeForUser(Builder $query, User $user): void
    {
        $query->whereIn($this->qualifyColumn('organization_id'), $user->organizationIds());
    }

    /**
     * Pruefung fuer Policies und Domainservices.
     */
    public function belongsToOrganization(Organization|string $organization): bool
    {
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        return $this->getAttribute('organization_id') === $id;
    }
}
