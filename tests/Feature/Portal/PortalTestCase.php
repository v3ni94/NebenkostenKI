<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gemeinsame Grundlage der Portaltests.
 *
 * Baut je Test einen vollstaendigen Mandanten mit Nutzer, Organisation,
 * Objekt, Einheit und Mietverhaeltnis. Zwei Aufrufe erzeugen zwei getrennte
 * Mandanten, mit denen die Mandantentrennung geprueft wird.
 *
 * Die Klasse endet nicht auf Test und wird deshalb nicht als Testklasse
 * eingesammelt.
 */
abstract class PortalTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{
     *     user: User,
     *     organization: Organization,
     *     property: Property,
     *     unit: Unit,
     *     tenancy: Tenancy
     * }
     */
    protected function mandant(OrganizationRole $rolle = OrganizationRole::OWNER): array
    {
        /** @var User $nutzer */
        $nutzer = User::factory()->create();

        /** @var Organization $organisation */
        $organisation = Organization::factory()->create();

        OrganizationUser::query()->create([
            'organization_id' => $organisation->getKey(),
            'user_id' => $nutzer->getKey(),
            'role' => $rolle,
            'joined_at' => now(),
        ]);

        /** @var Property $objekt */
        $objekt = Property::factory()->create([
            'organization_id' => $organisation->getKey(),
            'created_by_user_id' => $nutzer->getKey(),
        ]);

        /** @var Unit $einheit */
        $einheit = Unit::factory()->create([
            'organization_id' => $organisation->getKey(),
            'property_id' => $objekt->getKey(),
        ]);

        /** @var Tenancy $mietverhaeltnis */
        $mietverhaeltnis = Tenancy::factory()->create([
            'organization_id' => $organisation->getKey(),
            'property_id' => $objekt->getKey(),
            'unit_id' => $einheit->getKey(),
            'starts_on' => '2025-01-01',
            'ends_on' => null,
        ]);

        return [
            'user' => $nutzer,
            'organization' => $organisation,
            'property' => $objekt,
            'unit' => $einheit,
            'tenancy' => $mietverhaeltnis,
        ];
    }
}
