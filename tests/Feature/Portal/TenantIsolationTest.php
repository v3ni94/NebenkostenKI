<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Enums\BillingRunStatus;
use App\Models\BillingRun;
use App\Models\OccupancyPeriod;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Unit;
use App\Models\User;
use App\Models\VacancyPeriod;

/**
 * Mandantentrennung fuer jede Portalroute.
 *
 * Vorgabe des Masterprompts, Abschnitt 19 und ARCHITECTURE.md T1: Nutzer A darf
 * keine Entitaet von Nutzer B lesen, aendern oder loeschen, auch nicht bei
 * Kenntnis der ID. Die Antwort ist 403 oder 404 und verraet nichts ueber die
 * Existenz des fremden Datensatzes.
 */
final class TenantIsolationTest extends PortalTestCase
{
    public function test_jede_lesende_route_verweigert_fremde_entitaeten(): void
    {
        $a = $this->mandant();
        $b = $this->mandant();

        $fremdeLeerstand = VacancyPeriod::factory()->create([
            'organization_id' => $b['organization']->getKey(),
            'unit_id' => $b['unit']->getKey(),
            'starts_on' => '2025-07-01',
            'ends_on' => '2025-07-31',
        ]);

        $fremderLauf = BillingRun::factory()->create([
            'organization_id' => $b['organization']->getKey(),
            'property_id' => $b['property']->getKey(),
        ]);

        $routen = [
            route('portal.objekte.edit', ['property' => $b['property']->getKey()]),
            route('portal.einheiten.index', ['property' => $b['property']->getKey()]),
            route('portal.einheiten.create', ['property' => $b['property']->getKey()]),
            route('portal.einheiten.edit', ['unit' => $b['unit']->getKey()]),
            route('portal.mietverhaeltnisse.index', ['unit' => $b['unit']->getKey()]),
            route('portal.mietverhaeltnisse.create', ['unit' => $b['unit']->getKey()]),
            route('portal.mietverhaeltnisse.edit', ['tenancy' => $b['tenancy']->getKey()]),
            route('portal.abrechnungen.show', ['billingRun' => $fremderLauf->getKey()]),
        ];

        foreach ($routen as $url) {
            $antwort = $this->actingAs($a['user'])->get($url);

            self::assertContains(
                $antwort->getStatusCode(),
                [403, 404],
                'Die Route '.$url.' gibt eine fremde Entitaet frei.'
            );

            $inhalt = (string) $antwort->getContent();
            self::assertStringNotContainsString(
                (string) $b['property']->getAttribute('label'),
                $inhalt,
                'Die Fehlerseite verraet Daten des fremden Mandanten.'
            );
        }

        self::assertNotNull($fremdeLeerstand->getKey());
    }

    public function test_jede_schreibende_route_verweigert_fremde_entitaeten(): void
    {
        $a = $this->mandant();
        $b = $this->mandant();

        $fremdeLeerstand = VacancyPeriod::factory()->create([
            'organization_id' => $b['organization']->getKey(),
            'unit_id' => $b['unit']->getKey(),
            'starts_on' => '2025-07-01',
            'ends_on' => '2025-07-31',
        ]);

        $fremdeBelegung = OccupancyPeriod::factory()->create([
            'organization_id' => $b['organization']->getKey(),
            'tenancy_id' => $b['tenancy']->getKey(),
            'starts_on' => '2025-01-01',
            'ends_on' => '2025-12-31',
            'person_count' => 2,
        ]);

        $fremderLauf = BillingRun::factory()->create([
            'organization_id' => $b['organization']->getKey(),
            'property_id' => $b['property']->getKey(),
            'status' => BillingRunStatus::PREVIEW_READY,
        ]);

        $aufrufe = [
            ['put', route('portal.objekte.update', ['property' => $b['property']->getKey()]), [
                'label' => 'Uebernommen',
                'address_line' => 'Fremdweg 1',
                'postal_code' => '12345',
                'city' => 'Fremdstadt',
                'kind' => 'MEHRFAMILIENHAUS',
            ]],
            ['delete', route('portal.objekte.destroy', ['property' => $b['property']->getKey()]), []],
            ['post', route('portal.einheiten.store', ['property' => $b['property']->getKey()]), [
                'label' => 'WE fremd',
            ]],
            ['put', route('portal.einheiten.update', ['unit' => $b['unit']->getKey()]), [
                'label' => 'Uebernommen',
            ]],
            ['delete', route('portal.einheiten.destroy', ['unit' => $b['unit']->getKey()]), []],
            ['post', route('portal.mietverhaeltnisse.store', ['unit' => $b['unit']->getKey()]), [
                'tenant_display_name' => 'Fremd',
                'kind' => 'WOHNRAUM',
                'starts_on' => '2026-01-01',
            ]],
            ['put', route('portal.mietverhaeltnisse.update', ['tenancy' => $b['tenancy']->getKey()]), [
                'tenant_display_name' => 'Uebernommen',
                'kind' => 'WOHNRAUM',
                'starts_on' => '2025-01-01',
            ]],
            ['delete', route('portal.mietverhaeltnisse.destroy', ['tenancy' => $b['tenancy']->getKey()]), []],
            ['post', route('portal.leerstand.store', ['unit' => $b['unit']->getKey()]), [
                'starts_on' => '2026-01-01',
                'ends_on' => '2026-01-31',
            ]],
            ['delete', route('portal.leerstand.destroy', ['vacancy' => $fremdeLeerstand->getKey()]), []],
            ['post', route('portal.belegung.store', ['tenancy' => $b['tenancy']->getKey()]), [
                'starts_on' => '2025-01-01',
                'ends_on' => '2025-06-30',
                'person_count' => 2,
            ]],
            ['delete', route('portal.belegung.destroy', ['occupancy' => $fremdeBelegung->getKey()]), []],
            ['post', route('portal.abrechnungen.bestaetigen', ['billingRun' => $fremderLauf->getKey()]), [
                'werte_geprueft' => '1',
                'verantwortung_uebernommen' => '1',
            ]],
            ['post', route('portal.abrechnungen.abbrechen', ['billingRun' => $fremderLauf->getKey()]), []],
            ['delete', route('portal.abrechnungen.destroy', ['billingRun' => $fremderLauf->getKey()]), []],
        ];

        foreach ($aufrufe as [$verfahren, $url, $daten]) {
            $antwort = $this->actingAs($a['user'])->{$verfahren}($url, $daten);

            self::assertContains(
                $antwort->getStatusCode(),
                [403, 404],
                'Die Route '.$verfahren.' '.$url.' laesst einen Fremdzugriff zu.'
            );
        }

        // Der fremde Bestand ist unveraendert.
        self::assertSame(
            $b['property']->getAttribute('label'),
            Property::query()->withTrashed()->findOrFail($b['property']->getKey())->getAttribute('label')
        );
        self::assertNull(Property::query()->findOrFail($b['property']->getKey())->getAttribute('deleted_at'));
        self::assertNotNull(Unit::query()->find($b['unit']->getKey()));
        self::assertNotNull(Tenancy::query()->find($b['tenancy']->getKey()));
        self::assertNotNull(VacancyPeriod::query()->find($fremdeLeerstand->getKey()));
        self::assertNotNull(OccupancyPeriod::query()->find($fremdeBelegung->getKey()));
        self::assertSame(
            BillingRunStatus::PREVIEW_READY,
            BillingRun::query()->findOrFail($fremderLauf->getKey())->getAttribute('status')
        );
    }

    public function test_abrechnung_kann_nicht_fuer_ein_fremdes_objekt_angelegt_werden(): void
    {
        $a = $this->mandant();
        $b = $this->mandant();

        $antwort = $this->actingAs($a['user'])->post(route('portal.abrechnungen.store'), [
            'property_id' => $b['property']->getKey(),
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'mode' => 'FULL_PROPERTY',
        ]);

        self::assertContains($antwort->getStatusCode(), [403, 404]);
        self::assertSame(0, BillingRun::query()->count());
    }

    public function test_listen_zeigen_ausschliesslich_eigene_daten(): void
    {
        $a = $this->mandant();
        $b = $this->mandant();

        BillingRun::factory()->create([
            'organization_id' => $b['organization']->getKey(),
            'property_id' => $b['property']->getKey(),
        ]);

        $objekte = $this->actingAs($a['user'])->get(route('portal.objekte.index'));
        $objekte->assertOk();
        $objekte->assertSee((string) $a['property']->getAttribute('label'));
        $objekte->assertDontSee((string) $b['property']->getAttribute('label'));

        $abrechnungen = $this->actingAs($a['user'])->get(route('portal.abrechnungen.index'));
        $abrechnungen->assertOk();
        $abrechnungen->assertDontSee((string) $b['property']->getAttribute('label'));

        $dashboard = $this->actingAs($a['user'])->get(route('portal.dashboard'));
        $dashboard->assertOk();
        $dashboard->assertSee((string) $a['property']->getAttribute('label'));
        $dashboard->assertDontSee((string) $b['property']->getAttribute('label'));
    }

    public function test_konto_zeigt_nur_die_eigene_organisation(): void
    {
        $a = $this->mandant();
        $b = $this->mandant();

        $antwort = $this->actingAs($a['user'])->get(route('portal.konto.edit'));

        $antwort->assertOk();
        $antwort->assertSee((string) $a['organization']->getAttribute('name'));
        $antwort->assertDontSee((string) $b['organization']->getAttribute('name'));
        $antwort->assertDontSee((string) $b['property']->getAttribute('label'));
    }

    public function test_nutzer_ohne_mitgliedschaft_erhaelt_keinen_zugriff(): void
    {
        $ohne = User::factory()->create();

        $antwort = $this->actingAs($ohne)->get(route('portal.dashboard'));

        $antwort->assertForbidden();
        $antwort->assertSee('Ihrem Konto ist kein Bereich zugeordnet');
    }
}
