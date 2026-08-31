<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BillingRunStatus;
use App\Enums\OrganizationRole;
use App\Enums\UnitStatementStatus;
use App\Models\BillingRun;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Unit;
use App\Models\UnitStatement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prueft den Mandantenschutz.
 *
 * Nutzer A darf keine Entitaet des Nutzers B einsehen oder aendern, auch nicht bei
 * Kenntnis der ID. Geprueft werden sowohl die Policies mit Object-Level-Check als
 * auch das Query-Scoping der Modelle.
 */
class AuthorizationScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
    }

    public function test_nutzer_a_sieht_keine_entitaet_von_nutzer_b(): void
    {
        $a = $this->makeTenantWorld();
        $b = $this->makeTenantWorld();

        // Eigene Daten sind sichtbar.
        $this->assertTrue($a['user']->can('view', $a['property']));
        $this->assertTrue($a['user']->can('view', $a['unit']));
        $this->assertTrue($a['user']->can('view', $a['tenancy']));
        $this->assertTrue($a['user']->can('view', $a['billingRun']));
        $this->assertTrue($a['user']->can('view', $a['document']));
        $this->assertTrue($a['user']->can('view', $a['unitStatement']));
        $this->assertTrue($a['user']->can('view', $a['payment']));
        $this->assertTrue($a['user']->can('view', $a['organization']));

        // Fremde Daten sind niemals sichtbar.
        $this->assertFalse($a['user']->can('view', $b['property']));
        $this->assertFalse($a['user']->can('view', $b['unit']));
        $this->assertFalse($a['user']->can('view', $b['tenancy']));
        $this->assertFalse($a['user']->can('view', $b['billingRun']));
        $this->assertFalse($a['user']->can('view', $b['document']));
        $this->assertFalse($a['user']->can('view', $b['unitStatement']));
        $this->assertFalse($a['user']->can('view', $b['payment']));
        $this->assertFalse($a['user']->can('view', $b['organization']));
    }

    public function test_nutzer_a_darf_fremde_entitaeten_nicht_aendern_oder_loeschen(): void
    {
        $a = $this->makeTenantWorld();
        $b = $this->makeTenantWorld();

        foreach (['update', 'delete'] as $ability) {
            $this->assertFalse($a['user']->can($ability, $b['property']), $ability.' auf fremdes Objekt');
            $this->assertFalse($a['user']->can($ability, $b['unit']), $ability.' auf fremde Einheit');
            $this->assertFalse($a['user']->can($ability, $b['tenancy']), $ability.' auf fremdes Mietverhaeltnis');
            $this->assertFalse($a['user']->can($ability, $b['billingRun']), $ability.' auf fremden Lauf');
            $this->assertFalse($a['user']->can($ability, $b['document']), $ability.' auf fremdes Dokument');
        }
    }

    public function test_fremder_nutzer_sieht_keine_rechnung_und_keine_zahlung(): void
    {
        $a = $this->makeTenantWorld();
        $b = $this->makeTenantWorld();

        $invoice = Invoice::factory()->create([
            'organization_id' => $b['organization']->getKey(),
            'user_id' => $b['user']->getKey(),
        ]);

        $this->assertTrue($b['user']->can('view', $invoice));
        $this->assertFalse($a['user']->can('view', $invoice));
        $this->assertFalse($a['user']->can('download', $invoice));

        // Nach einer Kontoloeschung ist die Rechnung entkoppelt, greift die
        // Pruefung auf user_id.
        $decoupled = Invoice::factory()->create([
            'organization_id' => null,
            'user_id' => $b['user']->getKey(),
        ]);

        $this->assertTrue($b['user']->can('view', $decoupled));
        $this->assertFalse($a['user']->can('view', $decoupled));
    }

    public function test_query_scope_liefert_nur_eigene_datensaetze(): void
    {
        $a = $this->makeTenantWorld();
        $b = $this->makeTenantWorld();

        $ownProperties = Property::query()->forUser($a['user'])->pluck('id')->all();
        $this->assertSame([$a['property']->getKey()], $ownProperties);

        $ownRuns = BillingRun::query()->forUser($a['user'])->pluck('id')->all();
        $this->assertSame([$a['billingRun']->getKey()], $ownRuns);

        $ownDocuments = Document::query()->forOrganization($a['organization'])->pluck('id')->all();
        $this->assertSame([$a['document']->getKey()], $ownDocuments);

        $this->assertSame(0, Property::query()->forUser($a['user'])
            ->whereKey($b['property']->getKey())->count());
    }

    public function test_rolle_nur_lesen_darf_nicht_schreiben(): void
    {
        $world = $this->makeTenantWorld();

        $reader = User::factory()->create();
        OrganizationUser::factory()->create([
            'organization_id' => $world['organization']->getKey(),
            'user_id' => $reader->getKey(),
            'role' => OrganizationRole::READ_ONLY,
        ]);

        $this->assertTrue($reader->can('view', $world['property']));
        $this->assertFalse($reader->can('update', $world['property']));
        $this->assertFalse($reader->can('delete', $world['property']));
        $this->assertFalse($reader->can('update', $world['billingRun']));
        $this->assertFalse($reader->can('checkout', $world['billingRun']));
    }

    public function test_rolle_buchhaltung_darf_zahlen_aber_kein_objekt_loeschen(): void
    {
        $world = $this->makeTenantWorld();

        $accounting = User::factory()->create();
        OrganizationUser::factory()->create([
            'organization_id' => $world['organization']->getKey(),
            'user_id' => $accounting->getKey(),
            'role' => OrganizationRole::ACCOUNTING,
        ]);

        $world['billingRun']->update(['status' => BillingRunStatus::PREVIEW_READY]);
        $world['billingRun']->refresh();

        $this->assertTrue($accounting->can('checkout', $world['billingRun']));
        $this->assertTrue($accounting->can('update', $world['property']));
        $this->assertFalse($accounting->can('restore', $world['property']));
    }

    public function test_bezahlter_abrechnungslauf_ist_nicht_mehr_aenderbar(): void
    {
        $world = $this->makeTenantWorld();

        $world['billingRun']->update(['status' => BillingRunStatus::PAID, 'paid_at' => now()]);
        $world['billingRun']->refresh();

        $this->assertFalse($world['user']->can('update', $world['billingRun']));
        $this->assertFalse($world['user']->can('delete', $world['billingRun']));
        $this->assertTrue($world['user']->can('finalize', $world['billingRun']));
    }

    public function test_originaldatei_ist_niemals_abrufbar(): void
    {
        $world = $this->makeTenantWorld();

        $this->assertFalse($world['user']->can('downloadOriginal', $world['document']));
    }

    public function test_finale_abrechnung_ist_erst_nach_finalisierung_abrufbar(): void
    {
        $world = $this->makeTenantWorld();

        $this->assertTrue($world['user']->can('downloadPreview', $world['unitStatement']));
        $this->assertFalse($world['user']->can('downloadFinal', $world['unitStatement']));

        $world['unitStatement']->update(['status' => UnitStatementStatus::FINAL]);
        $world['unitStatement']->refresh();

        $this->assertTrue($world['user']->can('downloadFinal', $world['unitStatement']));
        $this->assertFalse($world['user']->can('update', $world['unitStatement']));
    }

    public function test_zahlungen_und_rechnungen_sind_unveraenderlich(): void
    {
        $world = $this->makeTenantWorld();

        $invoice = Invoice::factory()->create([
            'organization_id' => $world['organization']->getKey(),
            'user_id' => $world['user']->getKey(),
        ]);

        $this->assertFalse($world['user']->can('update', $world['payment']));
        $this->assertFalse($world['user']->can('delete', $world['payment']));
        $this->assertFalse($world['user']->can('refund', $world['payment']));
        $this->assertFalse($world['user']->can('update', $invoice));
        $this->assertFalse($world['user']->can('delete', $invoice));
    }

    public function test_gesperrtes_konto_hat_keinen_zugriff(): void
    {
        $world = $this->makeTenantWorld();

        $world['user']->delete();
        $deleted = User::withTrashed()->findOrFail($world['user']->getKey());

        $this->assertFalse($deleted->can('view', $world['property']));
    }

    /**
     * Erzeugt einen vollstaendigen Mandanten mit Objekt, Einheit, Mietverhaeltnis,
     * Abrechnungslauf, Dokument, Mieterabrechnung und Zahlung.
     *
     * @return array{
     *     user: User,
     *     organization: Organization,
     *     property: Property,
     *     unit: Unit,
     *     tenancy: Tenancy,
     *     billingRun: BillingRun,
     *     document: Document,
     *     unitStatement: UnitStatement,
     *     payment: Payment
     * }
     */
    private function makeTenantWorld(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->create();

        OrganizationUser::factory()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'role' => OrganizationRole::OWNER,
        ]);

        $property = Property::factory()->create([
            'organization_id' => $organization->getKey(),
            'created_by_user_id' => $user->getKey(),
        ]);

        $unit = Unit::factory()->create([
            'organization_id' => $organization->getKey(),
            'property_id' => $property->getKey(),
        ]);

        $tenancy = Tenancy::factory()->create([
            'organization_id' => $organization->getKey(),
            'property_id' => $property->getKey(),
            'unit_id' => $unit->getKey(),
        ]);

        $billingRun = BillingRun::factory()->create([
            'organization_id' => $organization->getKey(),
            'property_id' => $property->getKey(),
            'created_by_user_id' => $user->getKey(),
        ]);

        $document = Document::factory()->create([
            'organization_id' => $organization->getKey(),
            'billing_run_id' => $billingRun->getKey(),
        ]);

        $unitStatement = UnitStatement::factory()->create([
            'organization_id' => $organization->getKey(),
            'billing_run_id' => $billingRun->getKey(),
            'tenancy_id' => $tenancy->getKey(),
            'unit_id' => $unit->getKey(),
        ]);

        $payment = Payment::factory()->create([
            'organization_id' => $organization->getKey(),
            'billing_run_id' => $billingRun->getKey(),
            'user_id' => $user->getKey(),
        ]);

        return [
            'user' => $user,
            'organization' => $organization,
            'property' => $property,
            'unit' => $unit,
            'tenancy' => $tenancy,
            'billingRun' => $billingRun,
            'document' => $document,
            'unitStatement' => $unitStatement,
            'payment' => $payment,
        ];
    }
}
