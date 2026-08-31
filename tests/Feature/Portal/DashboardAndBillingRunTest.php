<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Application\BillingRun\CreateBillingRun;
use App\Enums\BillingMode;
use App\Enums\BillingRunStatus;
use App\Enums\PropertyKind;
use App\Enums\TenancyKind;
use App\Models\AuditLog;
use App\Models\BillingRun;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Support\Carbon;

/**
 * Dashboard und Anlage von Abrechnungslaeufen.
 */
final class DashboardAndBillingRunTest extends PortalTestCase
{
    public function test_dashboard_zeigt_die_vier_statuskategorien(): void
    {
        $mandant = $this->mandant();

        $antwort = $this->actingAs($mandant['user'])->get(route('portal.dashboard'));

        $antwort->assertOk();
        $antwort->assertSee('Erledigt');
        $antwort->assertSee('Bitte prüfen');
        $antwort->assertSee('Fehlt noch');
        $antwort->assertSee('Blockiert die Abrechnung');
    }

    public function test_dashboard_nennt_keinen_technischen_status(): void
    {
        $mandant = $this->mandant();

        BillingRun::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'property_id' => $mandant['property']->getKey(),
            'status' => BillingRunStatus::REVIEW_REQUIRED,
        ]);

        $antwort = $this->actingAs($mandant['user'])->get(route('portal.dashboard'));

        $antwort->assertOk();
        $antwort->assertSee('Einzelne Angaben sind zu prüfen');
        $antwort->assertDontSee('REVIEW_REQUIRED');
        $antwort->assertDontSee('BillingRunStatus');
        $antwort->assertDontSee('Exception');
    }

    public function test_fehlgeschlagener_lauf_erscheint_als_blockiert(): void
    {
        $mandant = $this->mandant();

        BillingRun::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'property_id' => $mandant['property']->getKey(),
            'status' => BillingRunStatus::FAILED,
            'failure_message' => 'Die Heizkostenabrechnung ist unvollständig.',
        ]);

        $antwort = $this->actingAs($mandant['user'])->get(route('portal.dashboard'));

        $antwort->assertOk();
        $antwort->assertSee('Blockiert die Abrechnung');
        $antwort->assertSee('Die Heizkostenabrechnung ist unvollständig.');
        $antwort->assertDontSee('FAILED');
    }

    public function test_finalisierter_lauf_erscheint_als_erledigt(): void
    {
        $mandant = $this->mandant();

        BillingRun::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'property_id' => $mandant['property']->getKey(),
            'status' => BillingRunStatus::FINALIZED,
            'paid_at' => now(),
            'finalized_at' => now(),
        ]);

        $antwort = $this->actingAs($mandant['user'])->get(route('portal.dashboard'));

        $antwort->assertOk();
        $antwort->assertSee('Die Abrechnungen sind erstellt und stehen zum Download bereit.');
    }

    public function test_objekt_ohne_einheit_erscheint_als_fehlt_noch(): void
    {
        $mandant = $this->mandant();
        Unit::query()->where('id', $mandant['unit']->getKey())->forceDelete();

        /** @var Property $leer */
        $leer = Property::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'label' => 'Objekt ohne Einheiten',
        ]);

        $antwort = $this->actingAs($mandant['user'])->get(route('portal.dashboard'));

        $antwort->assertOk();
        $antwort->assertSee('Für dieses Objekt ist noch keine Einheit erfasst.');
        self::assertNotNull($leer->getKey());
    }

    public function test_formular_schlaegt_das_vorjahr_als_zeitraum_vor(): void
    {
        $mandant = $this->mandant();
        $vorjahr = (int) now()->format('Y') - 1;

        $antwort = $this->actingAs($mandant['user'])->get(route('portal.abrechnungen.create'));

        $antwort->assertOk();
        $antwort->assertSee('value="'.$vorjahr.'-01-01"', false);
        $antwort->assertSee('value="'.$vorjahr.'-12-31"', false);
        $antwort->assertSee('01.01.'.$vorjahr.' bis 31.12.'.$vorjahr);
    }

    public function test_standardzeitraum_ist_das_vollstaendige_vorjahr(): void
    {
        $zeitraum = CreateBillingRun::defaultPeriod(Carbon::parse('2026-08-31'));

        self::assertSame('2025-01-01', $zeitraum['start']);
        self::assertSame('2025-12-31', $zeitraum['end']);
        self::assertSame(2025, $zeitraum['jahr']);
    }

    public function test_abrechnungslauf_wird_angelegt(): void
    {
        $mandant = $this->mandant();

        $antwort = $this->actingAs($mandant['user'])->post(route('portal.abrechnungen.store'), [
            'property_id' => $mandant['property']->getKey(),
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'mode' => 'FULL_PROPERTY',
        ]);

        /** @var BillingRun $lauf */
        $lauf = BillingRun::query()->firstOrFail();

        $antwort->assertRedirect(route('portal.abrechnungen.show', ['billingRun' => $lauf->getKey()]));
        self::assertSame(BillingRunStatus::DRAFT, $lauf->getAttribute('status'));
        self::assertSame(BillingMode::FULL_PROPERTY, $lauf->getAttribute('mode'));
        self::assertSame(2025, $lauf->getAttribute('billing_year'));
        self::assertSame($mandant['organization']->getKey(), $lauf->getAttribute('organization_id'));

        self::assertTrue(
            AuditLog::query()->where('action', CreateBillingRun::AUDIT_ACTION)->exists()
        );
    }

    public function test_unterjaehriger_zeitraum_ist_zulaessig(): void
    {
        $mandant = $this->mandant();

        $this->actingAs($mandant['user'])->post(route('portal.abrechnungen.store'), [
            'property_id' => $mandant['property']->getKey(),
            'period_start' => '2025-04-01',
            'period_end' => '2025-09-30',
            'mode' => 'QUICK_CONDO',
        ]);

        /** @var BillingRun $lauf */
        $lauf = BillingRun::query()->firstOrFail();

        self::assertSame('2025-04-01', $lauf->getAttribute('period_start')?->toDateString());
        self::assertSame('2025-09-30', $lauf->getAttribute('period_end')?->toDateString());
    }

    public function test_zu_langer_zeitraum_wird_abgelehnt(): void
    {
        $mandant = $this->mandant();

        $antwort = $this->actingAs($mandant['user'])
            ->from(route('portal.abrechnungen.create'))
            ->post(route('portal.abrechnungen.store'), [
                'property_id' => $mandant['property']->getKey(),
                'period_start' => '2024-01-01',
                'period_end' => '2025-12-31',
                'mode' => 'FULL_PROPERTY',
            ]);

        $antwort->assertSessionHasErrors('period_end');
        self::assertSame(0, BillingRun::query()->count());
    }

    public function test_endedatum_vor_beginn_wird_abgelehnt(): void
    {
        $mandant = $this->mandant();

        $antwort = $this->actingAs($mandant['user'])
            ->from(route('portal.abrechnungen.create'))
            ->post(route('portal.abrechnungen.store'), [
                'property_id' => $mandant['property']->getKey(),
                'period_start' => '2025-12-31',
                'period_end' => '2025-01-01',
                'mode' => 'FULL_PROPERTY',
            ]);

        $antwort->assertSessionHasErrors('period_end');
        self::assertStringContainsString(
            'nicht vor dem Beginn liegen',
            (string) session('errors')?->first('period_end')
        );
    }

    public function test_wegerkennung_schlaegt_die_schnellabrechnung_vor(): void
    {
        $mandant = $this->mandant();

        $mandant['property']->forceFill(['kind' => PropertyKind::EIGENTUMSWOHNUNG])->save();

        /** @var Property $frisch */
        $frisch = Property::query()->findOrFail($mandant['property']->getKey());

        self::assertSame(BillingMode::QUICK_CONDO, CreateBillingRun::suggestMode($frisch));
    }

    public function test_wegerkennung_schlaegt_bei_mehreren_einheiten_die_objektabrechnung_vor(): void
    {
        $mandant = $this->mandant();

        Unit::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'property_id' => $mandant['property']->getKey(),
        ]);

        /** @var Property $frisch */
        $frisch = Property::query()->findOrFail($mandant['property']->getKey());

        self::assertSame(BillingMode::FULL_PROPERTY, CreateBillingRun::suggestMode($frisch));
    }

    public function test_gewerbe_erzeugt_einen_hinweis_und_keine_automatische_finalisierung(): void
    {
        $mandant = $this->mandant();
        $mandant['tenancy']->forceFill(['kind' => TenancyKind::GEWERBE])->save();

        $formular = $this->actingAs($mandant['user'])->get(route('portal.abrechnungen.create', [
            'property' => $mandant['property']->getKey(),
        ]));

        $formular->assertOk();
        $formular->assertSee('gewerbliches Mietverhältnis');
        $formular->assertSee('nicht automatisch finalisiert');

        $anlegen = $this->actingAs($mandant['user'])->post(route('portal.abrechnungen.store'), [
            'property_id' => $mandant['property']->getKey(),
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'mode' => 'FULL_PROPERTY',
        ]);

        $anlegen->assertSessionHas('status');
        self::assertStringContainsString('nicht automatisch finalisiert', (string) session('status'));

        /** @var BillingRun $lauf */
        $lauf = BillingRun::query()->firstOrFail();
        self::assertSame(BillingRunStatus::DRAFT, $lauf->getAttribute('status'));
    }

    public function test_detailseite_zeigt_die_bestaetigung_vor_der_finalisierung(): void
    {
        $mandant = $this->mandant();

        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'property_id' => $mandant['property']->getKey(),
            'status' => BillingRunStatus::PREVIEW_READY,
        ]);

        $antwort = $this->actingAs($mandant['user'])->get(
            route('portal.abrechnungen.show', ['billingRun' => $lauf->getKey()])
        );

        $antwort->assertOk();
        $antwort->assertSee('Bestätigung vor der Finalisierung');
        $antwort->assertSee('alle Werte, Umlageschlüssel und Ergebnisse geprüft');
        $antwort->assertSee('Verantwortung für diese Betriebskostenabrechnung');
        // Beide Haken sind nicht vorangekreuzt.
        $antwort->assertDontSee('name="werte_geprueft" type="checkbox" value="1" checked', false);
    }

    public function test_zeitraeume_werden_deutsch_dargestellt(): void
    {
        $mandant = $this->mandant();

        BillingRun::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'property_id' => $mandant['property']->getKey(),
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
        ]);

        $antwort = $this->actingAs($mandant['user'])->get(route('portal.abrechnungen.index'));

        $antwort->assertOk();
        $antwort->assertSee('01.01.2025');
        $antwort->assertSee('31.12.2025');
    }
}
