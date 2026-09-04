<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Application\Wizard\PreviewBuilder;
use App\Application\Wizard\PreviewInvalidator;
use App\Enums\BillingRunStatus;
use App\Enums\GeneratedDocumentStatus;
use App\Models\BillingRun;
use App\Models\GeneratedDocument;
use App\Models\Organization;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Calculation\CalculationTestCase;

/**
 * Stammdatenaenderungen (Objekt, Vermieter, Einheit, Mietverhaeltnis,
 * Belegung, Leerstand) machen Vorschau und Bestaetigung ungueltig
 * (Befund N8). Jeder Fall laeuft ueber die Route, nicht ueber das Modell.
 */
final class PreviewInvalidationTest extends CalculationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_die_bearbeitung_eines_mietverhaeltnisses_macht_vorschau_und_bestaetigung_ungueltig(): void
    {
        $szenario = $this->vorschauMitBestaetigung();
        $mietverhaeltnis = $szenario['tenancies'][0];

        $this->actingAs($szenario['user'])->put(
            route('portal.mietverhaeltnisse.update', ['tenancy' => $mietverhaeltnis->getKey()]),
            $this->mietangaben($mietverhaeltnis, ['monthly_operating_prepayment_eur' => '200,00'])
        )->assertRedirect();

        $this->assertUngueltig($szenario);
    }

    public function test_ein_neues_mietverhaeltnis_macht_die_vorschau_ungueltig(): void
    {
        $szenario = $this->vorschauMitBestaetigung();
        $szenario['tenancies'][1]->forceFill(['ends_on' => '2025-06-30'])->save();

        // Die Vorschau wird nach der Vorbereitung neu erzeugt und bestaetigt,
        // damit nur der Anwendungsweg unten die Invalidierung ausloest.
        $this->erzeugeUndBestaetige($szenario);

        $this->actingAs($szenario['user'])->post(
            route('portal.mietverhaeltnisse.store', ['unit' => $szenario['units'][1]->getKey()]),
            [
                'tenant_display_name' => 'Mietpartei Nachfolge',
                'kind' => 'WOHNRAUM',
                'starts_on' => '2025-07-01',
                'monthly_operating_prepayment_eur' => '150,00',
            ]
        )->assertRedirect();

        $this->assertUngueltig($szenario);
    }

    public function test_das_entfernen_eines_mietverhaeltnisses_macht_die_vorschau_ungueltig(): void
    {
        $szenario = $this->vorschauMitBestaetigung();

        $this->actingAs($szenario['user'])->delete(
            route('portal.mietverhaeltnisse.destroy', ['tenancy' => $szenario['tenancies'][1]->getKey()])
        )->assertRedirect();

        $this->assertUngueltig($szenario);
    }

    public function test_ein_leerstand_macht_die_vorschau_ungueltig(): void
    {
        $szenario = $this->vorschauMitBestaetigung();
        $szenario['tenancies'][1]->forceFill(['ends_on' => '2025-06-30'])->save();
        $this->erzeugeUndBestaetige($szenario);

        $this->actingAs($szenario['user'])->post(
            route('portal.leerstand.store', ['unit' => $szenario['units'][1]->getKey()]),
            ['starts_on' => '2025-07-01', 'ends_on' => '2025-12-31', 'reason' => 'Renovierung']
        )->assertRedirect();

        $this->assertUngueltig($szenario);
    }

    public function test_eine_belegung_mit_personenanzahl_macht_die_vorschau_ungueltig(): void
    {
        $szenario = $this->vorschauMitBestaetigung();

        $this->actingAs($szenario['user'])->post(
            route('portal.belegung.store', ['tenancy' => $szenario['tenancies'][0]->getKey()]),
            ['starts_on' => '2025-01-01', 'ends_on' => '2025-06-30', 'person_count' => 3]
        )->assertRedirect();

        $this->assertUngueltig($szenario);
    }

    public function test_die_bearbeitung_einer_einheit_macht_die_vorschau_ungueltig(): void
    {
        $szenario = $this->vorschauMitBestaetigung();

        $this->actingAs($szenario['user'])->put(
            route('portal.einheiten.update', ['unit' => $szenario['units'][0]->getKey()]),
            ['label' => 'Wohnung A', 'living_area_sqm' => '120']
        )->assertRedirect();

        $this->assertUngueltig($szenario);
    }

    public function test_das_entfernen_einer_einheit_macht_die_vorschau_ungueltig(): void
    {
        $szenario = $this->vorschauMitBestaetigung();

        $this->actingAs($szenario['user'])->delete(
            route('portal.einheiten.destroy', ['unit' => $szenario['units'][1]->getKey()])
        )->assertRedirect();

        $this->assertUngueltig($szenario);
    }

    public function test_die_bearbeitung_des_vermieters_macht_die_vorschau_ungueltig(): void
    {
        $szenario = $this->vorschauMitBestaetigung();

        $this->actingAs($szenario['user'])->put(
            route('portal.objekte.vermieter.update', ['property' => $szenario['property']->getKey()]),
            [
                'sender_name' => 'Beispiel Vermietung Sonnenweg, neuer Absender',
                'address_line' => 'Sonnenweg 4',
                'postal_code' => '40789',
                'city' => 'Monheim am Rhein',
            ]
        )->assertRedirect();

        $this->assertUngueltig($szenario);
    }

    public function test_die_bearbeitung_des_objekts_macht_die_vorschau_ungueltig(): void
    {
        $szenario = $this->vorschauMitBestaetigung();

        $this->actingAs($szenario['user'])->put(
            route('portal.objekte.update', ['property' => $szenario['property']->getKey()]),
            [
                'label' => 'Beispielobjekt Sonnenweg 4',
                'address_line' => 'Sonnenweg 4a',
                'postal_code' => '40789',
                'city' => 'Monheim am Rhein',
                'kind' => 'MEHRFAMILIENHAUS',
                'total_living_area_sqm' => '150',
                'mea_denominator' => '1000',
            ]
        )->assertRedirect();

        $this->assertUngueltig($szenario);
    }

    public function test_ein_bezahlter_lauf_bleibt_von_stammdatenaenderungen_unberuehrt(): void
    {
        $szenario = $this->vorschauMitBestaetigung();
        $szenario['billingRun']->forceFill(['status' => BillingRunStatus::PAID, 'paid_at' => now()])->save();

        $this->actingAs($szenario['user'])->put(
            route('portal.mietverhaeltnisse.update', ['tenancy' => $szenario['tenancies'][0]->getKey()]),
            $this->mietangaben($szenario['tenancies'][0], ['monthly_operating_prepayment_eur' => '200,00'])
        )->assertRedirect();

        $lauf = $szenario['billingRun']->refresh();

        self::assertTrue(app(PreviewBuilder::class)->isValid($lauf));
        self::assertNotNull($lauf->review_confirmed_at);
    }

    public function test_ein_mietverhaeltnis_ausserhalb_des_zeitraums_beruehrt_den_lauf_nicht(): void
    {
        $szenario = $this->vorschauMitBestaetigung();

        /** @var Tenancy $spaeter */
        $spaeter = Tenancy::factory()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'property_id' => $szenario['property']->getKey(),
            'unit_id' => $szenario['units'][0]->getKey(),
            'starts_on' => '2026-01-01',
            'ends_on' => null,
        ]);

        self::assertSame(0, app(PreviewInvalidator::class)->forTenancy($spaeter, $szenario['user']));
        self::assertTrue(app(PreviewBuilder::class)->isValid($szenario['billingRun']->refresh()));
    }

    /**
     * @return array{user: User, billingRun: BillingRun, tenancies: list<Tenancy>, units: list<Unit>, property: Property, organization: Organization}
     */
    private function vorschauMitBestaetigung(): array
    {
        $szenario = $this->szenario();

        $this->erzeugeUndBestaetige($szenario);

        return $szenario;
    }

    /**
     * @param  array{user: User, billingRun: BillingRun}  $szenario
     */
    private function erzeugeUndBestaetige(array $szenario): void
    {
        app(PreviewBuilder::class)->rebuild($szenario['billingRun'], $szenario['user']);

        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorschau.bestaetigen', ['billingRun' => $szenario['billingRun']->getKey()]),
            ['bestaetigung' => '1']
        )->assertRedirect();

        $lauf = $szenario['billingRun']->refresh();

        self::assertTrue(app(PreviewBuilder::class)->isValid($lauf));
        self::assertNotNull($lauf->review_confirmed_at);
    }

    /**
     * @param  array{billingRun: BillingRun}  $szenario
     */
    private function assertUngueltig(array $szenario): void
    {
        $lauf = $szenario['billingRun']->refresh();

        self::assertFalse(app(PreviewBuilder::class)->isValid($lauf));
        self::assertNull($lauf->review_confirmed_at);
        self::assertNull($lauf->responsibility_confirmed_at);

        self::assertSame(
            0,
            GeneratedDocument::query()
                ->where('billing_run_id', $lauf->getKey())
                ->where('status', GeneratedDocumentStatus::AKTIV->value)
                ->count()
        );
    }

    /**
     * @param  array<string, string>  $abweichungen
     * @return array<string, string>
     */
    private function mietangaben(Tenancy $mietverhaeltnis, array $abweichungen = []): array
    {
        return array_merge([
            'tenant_display_name' => $mietverhaeltnis->tenant_display_name,
            'kind' => 'WOHNRAUM',
            'starts_on' => '2025-01-01',
            'heating_prepayment_separate' => '1',
            'monthly_operating_prepayment_eur' => '150,00',
            'monthly_heating_prepayment_eur' => '90,00',
        ], $abweichungen);
    }
}
