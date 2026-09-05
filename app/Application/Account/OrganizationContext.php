<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Enums\OrganizationRole;
use App\Models\BillingRun;
use App\Models\Landlord;
use App\Models\OccupancyPeriod;
use App\Models\Organization;
use App\Models\Property;
use App\Models\ReminderPreference;
use App\Models\Tenancy;
use App\Models\Unit;
use App\Models\User;
use App\Models\VacancyPeriod;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Aktiver Mandant der laufenden Anfrage.
 *
 * Vorgabe des Masterprompts, Abschnitt 19: Jede Query wird nach Organisation
 * gescopet, zusaetzlich greift je Entitaet eine Policy mit Object-Level-Check.
 * Eine Freigabe allein aufgrund einer erratbaren URL-ID ist unzulaessig.
 *
 * Diese Klasse ist die einzige Stelle, an der die Portalcontroller ihre
 * Startqueries beziehen. Damit ist das Scoping nicht in jedem Controller neu zu
 * schreiben und kann nicht versehentlich entfallen. Der Object-Level-Check der
 * Policy ersetzt sie nicht, beide Ebenen greifen zusammen:
 *
 *     $objekt = $context->properties()->findOrFail($id);   // Mandantenscope
 *     $this->authorize('update', $objekt);                 // Policy
 *
 * Die Instanz wird von App\Http\Middleware\EnsureOrganizationContext gesetzt
 * und ist als scoped Singleton je Anfrage gebunden. Ohne gesetzten Mandanten
 * werfen die Zugriffe eine RuntimeException, damit eine ungescopte Query nicht
 * unbemerkt durchlaeuft.
 */
class OrganizationContext
{
    private ?Organization $organization = null;

    private ?User $user = null;

    public function set(Organization $organization, User $user): void
    {
        $this->organization = $organization;
        $this->user = $user;
    }

    public function isSet(): bool
    {
        return $this->organization !== null && $this->user !== null;
    }

    public function organization(): Organization
    {
        if ($this->organization === null) {
            throw new RuntimeException(
                'Es ist keine aktive Organisation gesetzt. Die Route muss die Middleware organisation verwenden.'
            );
        }

        return $this->organization;
    }

    public function user(): User
    {
        if ($this->user === null) {
            throw new RuntimeException(
                'Es ist kein angemeldeter Nutzer gesetzt. Die Route muss die Middleware auth verwenden.'
            );
        }

        return $this->user;
    }

    public function organizationId(): string
    {
        $id = $this->organization()->getKey();

        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Die aktive Organisation besitzt keinen Schluessel.');
        }

        return $id;
    }

    public function role(): ?OrganizationRole
    {
        return $this->user()->roleIn($this->organization());
    }

    /**
     * @return Builder<Property>
     */
    public function properties(): Builder
    {
        return Property::query()->where('organization_id', $this->organizationId());
    }

    /**
     * @return Builder<Unit>
     */
    public function units(): Builder
    {
        return Unit::query()->where('organization_id', $this->organizationId());
    }

    /**
     * @return Builder<Tenancy>
     */
    public function tenancies(): Builder
    {
        return Tenancy::query()->where('organization_id', $this->organizationId());
    }

    /**
     * @return Builder<VacancyPeriod>
     */
    public function vacancyPeriods(): Builder
    {
        return VacancyPeriod::query()->where('organization_id', $this->organizationId());
    }

    /**
     * @return Builder<OccupancyPeriod>
     */
    public function occupancyPeriods(): Builder
    {
        return OccupancyPeriod::query()->where('organization_id', $this->organizationId());
    }

    /**
     * @return Builder<BillingRun>
     */
    public function billingRuns(): Builder
    {
        return BillingRun::query()->where('organization_id', $this->organizationId());
    }

    /**
     * @return Builder<Landlord>
     */
    public function landlords(): Builder
    {
        return Landlord::query()->where('organization_id', $this->organizationId());
    }

    /**
     * Erinnerungseinstellungen des angemeldeten Nutzers im aktiven Mandanten.
     *
     * @return Builder<ReminderPreference>
     */
    public function reminderPreferences(): Builder
    {
        return ReminderPreference::query()
            ->where('organization_id', $this->organizationId())
            ->where('user_id', $this->user()->getKey());
    }
}
