<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Models\AdminRoleAssignment;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Grundlage der Tests des internen Adminbereichs.
 *
 * Die Klasse endet nicht auf Test und wird deshalb nicht als Testklasse
 * eingesammelt.
 */
abstract class AdminTestCase extends TestCase
{
    use RefreshDatabase;

    protected string $artefaktverzeichnis = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Eigene Ablage je Test, damit ein paralleler Lauf das gemeinsame
        // Testverzeichnis nicht leert.
        $this->artefaktverzeichnis = storage_path('framework/testing/admin-'.Str::random(12));
        config()->set('filesystems.disks.local.root', $this->artefaktverzeichnis);
    }

    protected function tearDown(): void
    {
        if ($this->artefaktverzeichnis !== '' && File::isDirectory($this->artefaktverzeichnis)) {
            File::deleteDirectory($this->artefaktverzeichnis);
        }

        parent::tearDown();
    }

    /**
     * Interner Nutzer mit aktiver Adminrolle und bestaetigtem Zweitfaktor.
     */
    protected function interneKennung(AdminRole $rolle = AdminRole::ADMIN, bool $zweitfaktor = true): User
    {
        /** @var User $nutzer */
        $nutzer = User::factory()->create([
            'two_factor_confirmed_at' => $zweitfaktor ? now() : null,
        ]);

        AdminRoleAssignment::query()->create([
            'user_id' => $nutzer->getKey(),
            'role' => $rolle,
            'granted_at' => now(),
        ]);

        return $nutzer;
    }

    /**
     * Kundennutzer mit Organisation und Kundenrolle, ohne jede interne Rolle.
     *
     * @return array{user: User, organization: Organization}
     */
    protected function kunde(): array
    {
        /** @var User $nutzer */
        $nutzer = User::factory()->create();

        /** @var Organization $organisation */
        $organisation = Organization::factory()->create();

        OrganizationUser::query()->create([
            'organization_id' => $organisation->getKey(),
            'user_id' => $nutzer->getKey(),
            'role' => \App\Enums\OrganizationRole::OWNER,
            'joined_at' => now(),
        ]);

        return ['user' => $nutzer, 'organization' => $organisation];
    }

    /**
     * Alle lesenden Adminrouten. Grundlage der Berechtigungstests.
     *
     * @return list<string>
     */
    public static function lesendeRouten(): array
    {
        return [
            '/admin',
            '/admin/livegang',
            '/admin/datenschutz',
            '/admin/verarbeitung',
            '/admin/ki',
            '/admin/zahlungen',
            '/admin/preise',
            '/admin/nutzer',
            '/admin/organisationen',
            '/admin/kommunikation',
            '/admin/versionen',
            '/admin/technik',
            '/admin/kennzahlen',
            '/admin/protokoll',
        ];
    }

    /**
     * Bestaetigte Pflichtangaben des Betreibers.
     *
     * Die Werte sind frei erfundene Beispielangaben beziehungsweise die
     * allgemein dokumentierte Beispiel-IBAN. Es werden ausdruecklich keine
     * echten Steuer- oder Bankdaten verwendet.
     */
    protected function bestaetigteBetreiberstammdaten(): void
    {
        config()->set('smartabrechnen.operator.tax_id', '000/0000/0000');
        config()->set('smartabrechnen.operator.vat_id', 'DE000000000');
        config()->set('smartabrechnen.operator.iban', 'DE02120300000000202051');
        config()->set('smartabrechnen.operator.bic', 'BYLADEM1001');
        config()->set('smartabrechnen.operator.masterdata_confirmed', true);
    }
}
