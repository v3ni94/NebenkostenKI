<?php

declare(strict_types=1);

namespace Tests\Feature\Calculation;

use App\Enums\AllocationKeySource;
use App\Enums\AllocationKeyType;
use App\Enums\BillingMode;
use App\Enums\CostItemStatus;
use App\Enums\OrganizationRole;
use App\Enums\PrepaymentKind;
use App\Enums\ValueSource;
use App\Http\Controllers\Portal\Wizard\AllocationKeyController;
use App\Http\Controllers\Portal\Wizard\AuditReportController;
use App\Http\Controllers\Portal\Wizard\PrepaymentController;
use App\Http\Controllers\Portal\Wizard\PreviewController;
use App\Models\AllocationKey;
use App\Models\AllocationKeyValue;
use App\Models\BillingRun;
use App\Models\CostCategory;
use App\Models\CostItem;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Prepayment;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\CostCategorySeeder;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Portal\PortalTestCase;

/**
 * Grundlage der Tests zur Berechnungsorchestrierung und zu den Schritten 7
 * bis 10 des geführten Ablaufs.
 *
 * Die Routen dieses Arbeitspakets werden hier registriert. Die Eintragung in
 * routes/portal.php erfolgt zentral; die Definitionen sind identisch mit der
 * im Bericht gelisteten Routenliste.
 *
 * Die Klasse endet nicht auf Test und wird deshalb nicht als Testklasse
 * eingesammelt.
 */
abstract class CalculationTestCase extends PortalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CostCategorySeeder::class);
        self::registriereRouten();

        app('router')->getRoutes()->refreshNameLookups();
    }

    /**
     * Routenliste des Arbeitspakets.
     */
    public static function registriereRouten(): void
    {
        if (Route::has('portal.wizard.vorauszahlungen')) {
            return;
        }

        Route::prefix('app')
            ->name('portal.')
            ->middleware(['web', 'auth', 'organisation'])
            ->group(function (): void {
                Route::get('/abrechnungen/{billingRun}/vorauszahlungen', [PrepaymentController::class, 'show'])
                    ->name('wizard.vorauszahlungen');
                Route::post('/abrechnungen/{billingRun}/vorauszahlungen', [PrepaymentController::class, 'store'])
                    ->name('wizard.vorauszahlungen.speichern');
                Route::post('/abrechnungen/{billingRun}/vorauszahlungen/weiter', [PrepaymentController::class, 'proceed'])
                    ->name('wizard.vorauszahlungen.weiter');

                Route::get('/abrechnungen/{billingRun}/verteilerschluessel', [AllocationKeyController::class, 'show'])
                    ->name('wizard.schluessel');
                Route::post('/abrechnungen/{billingRun}/verteilerschluessel', [AllocationKeyController::class, 'store'])
                    ->name('wizard.schluessel.speichern');
                Route::post('/abrechnungen/{billingRun}/verteilerschluessel/weiter', [AllocationKeyController::class, 'proceed'])
                    ->name('wizard.schluessel.weiter');
                Route::post('/abrechnungen/{billingRun}/verteilerschluessel/ersatzverteilung/{unit}', [AllocationKeyController::class, 'confirmSubstitute'])
                    ->name('wizard.schluessel.ersatzverteilung');

                Route::get('/abrechnungen/{billingRun}/pruefbericht', [AuditReportController::class, 'show'])
                    ->name('wizard.pruefbericht');
                Route::post('/abrechnungen/{billingRun}/pruefbericht/weiter', [AuditReportController::class, 'proceed'])
                    ->name('wizard.pruefbericht.weiter');
                Route::post('/abrechnungen/{billingRun}/pruefbericht/{issue}/entscheiden', [AuditReportController::class, 'decide'])
                    ->name('wizard.pruefbericht.entscheiden');

                Route::get('/abrechnungen/{billingRun}/vorschau', [PreviewController::class, 'show'])
                    ->name('wizard.vorschau');
                Route::post('/abrechnungen/{billingRun}/vorschau/erzeugen', [PreviewController::class, 'rebuild'])
                    ->name('wizard.vorschau.erzeugen');
                Route::post('/abrechnungen/{billingRun}/vorschau/bestaetigen', [PreviewController::class, 'confirm'])
                    ->name('wizard.vorschau.bestaetigen');
            });
    }

    /**
     * Vollständiges Szenario: ein Objekt mit zwei Einheiten, je einem
     * Mietverhältnis über das ganze Jahr, einer bestätigten Kostenposition,
     * einem Wohnflächenschlüssel und erfassten Vorauszahlungen.
     *
     * @return array{
     *     user: User,
     *     organization: Organization,
     *     property: Property,
     *     billingRun: BillingRun,
     *     units: list<Unit>,
     *     tenancies: list<Tenancy>,
     *     category: CostCategory,
     *     costItem: CostItem,
     *     key: AllocationKey
     * }
     */
    protected function szenario(): array
    {
        /** @var User $nutzer */
        $nutzer = User::factory()->create();

        /** @var Organization $organisation */
        $organisation = Organization::factory()->create();

        OrganizationUser::query()->create([
            'organization_id' => $organisation->getKey(),
            'user_id' => $nutzer->getKey(),
            'role' => OrganizationRole::OWNER,
            'joined_at' => now(),
        ]);

        /** @var Property $objekt */
        $objekt = Property::factory()->create([
            'organization_id' => $organisation->getKey(),
            'created_by_user_id' => $nutzer->getKey(),
            'label' => 'Beispielobjekt Sonnenweg 4',
            'mea_denominator' => '1000.000000',
        ]);

        $einheiten = [];
        $mietverhaeltnisse = [];

        foreach ([['A', '100.0000', '100.0000'], ['B', '50.0000', '50.0000']] as [$name, $flaeche, $beheizt]) {
            /** @var Unit $einheit */
            $einheit = Unit::factory()->create([
                'organization_id' => $organisation->getKey(),
                'property_id' => $objekt->getKey(),
                'label' => 'Wohnung '.$name,
                'living_area_sqm' => $flaeche,
                'heated_area_sqm' => $beheizt,
                'mea' => $name === 'A' ? '600.000000' : '400.000000',
                'individual_key_1_value' => $name === 'A' ? '2.0000' : '1.0000',
            ]);

            /** @var Tenancy $mietverhaeltnis */
            $mietverhaeltnis = Tenancy::factory()->create([
                'organization_id' => $organisation->getKey(),
                'property_id' => $objekt->getKey(),
                'unit_id' => $einheit->getKey(),
                'tenant_display_name' => 'Mietpartei '.$name,
                'starts_on' => '2025-01-01',
                'ends_on' => null,
                'monthly_operating_prepayment_cent' => 15000,
                'monthly_heating_prepayment_cent' => 9000,
                'heating_prepayment_separate' => true,
            ]);

            $einheiten[] = $einheit;
            $mietverhaeltnisse[] = $mietverhaeltnis;
        }

        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->create([
            'organization_id' => $organisation->getKey(),
            'property_id' => $objekt->getKey(),
            'created_by_user_id' => $nutzer->getKey(),
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'billing_year' => 2025,
            'mode' => BillingMode::FULL_PROPERTY,
            'heating_supply_case' => null,
            'previous_billing_run_id' => null,
        ]);

        $kategorie = $this->kategorie('GEBAEUDEREINIGUNG');

        /** @var CostItem $position */
        $position = CostItem::factory()->create([
            'organization_id' => $organisation->getKey(),
            'billing_run_id' => $lauf->getKey(),
            'cost_category_id' => $kategorie->getKey(),
            'description' => 'Gebäudereinigung Treppenhaus',
            'amount_cent' => 120000,
            'status' => CostItemStatus::BESTAETIGT,
            'confirmed_at' => now(),
        ]);

        $schluessel = $this->schluessel($lauf, $kategorie, AllocationKeyType::WOHNFLAECHE, nenner: '150.000000');

        foreach ($mietverhaeltnisse as $mietverhaeltnis) {
            $this->vorauszahlung($lauf, $mietverhaeltnis);
        }

        return [
            'user' => $nutzer,
            'organization' => $organisation,
            'property' => $objekt,
            'billingRun' => $lauf->refresh(),
            'units' => $einheiten,
            'tenancies' => $mietverhaeltnisse,
            'category' => $kategorie,
            'costItem' => $position,
            'key' => $schluessel,
        ];
    }

    protected function kategorie(string $code): CostCategory
    {
        $kategorie = CostCategory::query()->where('code', $code)->first();

        self::assertInstanceOf(CostCategory::class, $kategorie);

        return $kategorie;
    }

    /**
     * Bestätigter Schlüssel aus dem Mietvertrag, ohne abweichenden Nenner.
     */
    protected function schluessel(
        BillingRun $lauf,
        CostCategory $kategorie,
        AllocationKeyType $typ,
        AllocationKeySource $quelle = AllocationKeySource::MIETVERTRAG,
        ?string $nenner = null,
    ): AllocationKey {
        /** @var AllocationKey $schluessel */
        $schluessel = AllocationKey::factory()->create([
            'organization_id' => $lauf->getAttribute('organization_id'),
            'billing_run_id' => $lauf->getKey(),
            'cost_category_id' => $kategorie->getKey(),
            'key_type' => $typ,
            'source' => $quelle,
            'denominator' => $nenner,
            'label' => $typ->label(),
            'confirmed_at' => $quelle === AllocationKeySource::DEFAULT ? null : now(),
        ]);

        return $schluessel;
    }

    protected function schluesselwert(
        AllocationKey $schluessel,
        string $numerator,
        ?Unit $einheit = null,
        ?Tenancy $mietverhaeltnis = null,
    ): AllocationKeyValue {
        /** @var AllocationKeyValue $wert */
        $wert = AllocationKeyValue::query()->create([
            'organization_id' => $schluessel->getAttribute('organization_id'),
            'allocation_key_id' => $schluessel->getKey(),
            'unit_id' => $einheit?->getKey(),
            'tenancy_id' => $mietverhaeltnis?->getKey(),
            'numerator' => $numerator,
            'source' => ValueSource::MANUELL,
        ]);

        return $wert;
    }

    protected function vorauszahlung(
        BillingRun $lauf,
        Tenancy $mietverhaeltnis,
        ?int $istCent = 288000,
        bool $annahme = false,
    ): Prepayment {
        /** @var Prepayment $vorauszahlung */
        $vorauszahlung = Prepayment::query()->create([
            'organization_id' => $lauf->getAttribute('organization_id'),
            'billing_run_id' => $lauf->getKey(),
            'tenancy_id' => $mietverhaeltnis->getKey(),
            'kind' => PrepaymentKind::BETRIEBSKOSTEN,
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'target_cent' => 288000,
            'actual_cent' => $annahme ? 288000 : $istCent,
            'source' => $annahme ? ValueSource::SOLL_ANNAHME : ValueSource::ZAHLUNGSUEBERSICHT,
            'assumed_equal_to_target' => $annahme,
            'confirmed_at' => now(),
        ]);

        return $vorauszahlung;
    }
}
