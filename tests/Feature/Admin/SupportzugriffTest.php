<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\BillingRun;
use App\Models\Organization;
use App\Models\Property;

/**
 * Supportzugriff auf Kundendaten (Masterprompt 19, ARCHITECTURE.md T10).
 *
 * Jeder Einblick verlangt eine Begruendung und erzeugt einen Audit-Eintrag mit
 * Akteur, Aktion, Entitaet, Zeitpunkt und gekuerzter IP.
 */
final class SupportzugriffTest extends AdminTestCase
{
    public function test_ohne_begruendung_gibt_es_keinen_einblick_in_eine_organisation(): void
    {
        /** @var Organization $organisation */
        $organisation = Organization::factory()->create();

        $antwort = $this->actingAs($this->interneKennung())
            ->get('/admin/organisationen/'.$organisation->getKey());

        $antwort->assertRedirect(route('admin.support.begruendung', [
            'entitaet' => 'organisation',
            'id' => $organisation->getKey(),
        ]));
    }

    public function test_das_begruendungsformular_nennt_den_zweck_und_die_protokollierung(): void
    {
        /** @var Organization $organisation */
        $organisation = Organization::factory()->create();

        $antwort = $this->actingAs($this->interneKennung())
            ->get('/admin/supportzugriff/organisation/'.$organisation->getKey());

        $antwort->assertOk();
        $antwort->assertSee('Supportzugriff begründen');
        $antwort->assertSee('protokolliert');
    }

    public function test_eine_zu_kurze_begruendung_wird_abgelehnt(): void
    {
        /** @var Organization $organisation */
        $organisation = Organization::factory()->create();

        $this->actingAs($this->interneKennung())
            ->post('/admin/supportzugriff/organisation/'.$organisation->getKey(), ['grund' => 'kurz'])
            ->assertSessionHasErrors('grund');

        self::assertSame(0, AuditLog::query()->where('action', 'admin.support.access_granted')->count());
    }

    public function test_die_freischaltung_erzeugt_einen_audit_eintrag_mit_begruendung_und_gekuerzter_ip(): void
    {
        /** @var Organization $organisation */
        $organisation = Organization::factory()->create();
        $akteur = $this->interneKennung();
        $grund = 'Kunde meldet eine fehlende Mieterabrechnung, Ticket 4711.';

        $this->actingAs($akteur)
            ->post('/admin/supportzugriff/organisation/'.$organisation->getKey(), ['grund' => $grund])
            ->assertRedirect(route('admin.organisationen.show', $organisation));

        /** @var AuditLog $eintrag */
        $eintrag = AuditLog::query()->where('action', 'admin.support.access_granted')->firstOrFail();

        self::assertSame($akteur->getKey(), $eintrag->getAttribute('actor_user_id'));
        self::assertSame($grund, (string) $eintrag->getAttribute('reason'));
        self::assertNotNull($eintrag->getAttribute('occurred_at'));
        self::assertSame('ADMIN', $eintrag->getAttribute('actor_admin_role')?->value);

        // Die IP ist gekuerzt. Der Testclient meldet 127.0.0.1.
        self::assertSame('127.0.0.0', (string) $eintrag->getAttribute('ip_truncated'));
    }

    public function test_nach_der_freischaltung_ist_der_einblick_moeglich_und_wird_protokolliert(): void
    {
        /** @var Organization $organisation */
        $organisation = Organization::factory()->create();
        $akteur = $this->interneKennung();

        $this->actingAs($akteur)->post(
            '/admin/supportzugriff/organisation/'.$organisation->getKey(),
            ['grund' => 'Kunde meldet eine fehlende Mieterabrechnung, Ticket 4711.'],
        );

        $antwort = $this->actingAs($akteur)->get('/admin/organisationen/'.$organisation->getKey());

        $antwort->assertOk();
        $antwort->assertSee($organisation->getAttribute('name'));
        $antwort->assertSee('Revisionsprotokoll');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.support.record_viewed',
            'subject_id' => $organisation->getKey(),
        ]);
    }

    public function test_die_freischaltung_gilt_nur_fuer_den_angefragten_datensatz(): void
    {
        /** @var Organization $eine */
        $eine = Organization::factory()->create();
        /** @var Organization $andere */
        $andere = Organization::factory()->create();
        $akteur = $this->interneKennung();

        $this->actingAs($akteur)->post(
            '/admin/supportzugriff/organisation/'.$eine->getKey(),
            ['grund' => 'Kunde meldet eine fehlende Mieterabrechnung, Ticket 4711.'],
        );

        $this->actingAs($akteur)
            ->get('/admin/organisationen/'.$andere->getKey())
            ->assertRedirect(route('admin.support.begruendung', [
                'entitaet' => 'organisation',
                'id' => $andere->getKey(),
            ]));
    }

    public function test_ein_objekt_verlangt_eine_eigene_begruendung(): void
    {
        /** @var Property $objekt */
        $objekt = Property::factory()->create();
        $akteur = $this->interneKennung();

        $this->actingAs($akteur)
            ->get('/admin/objekte/'.$objekt->getKey())
            ->assertRedirect(route('admin.support.begruendung', [
                'entitaet' => 'objekt',
                'id' => $objekt->getKey(),
            ]));

        $this->actingAs($akteur)->post(
            '/admin/supportzugriff/objekt/'.$objekt->getKey(),
            ['grund' => 'Prüfung einer gemeldeten Flächenabweichung, Ticket 4712.'],
        );

        $this->actingAs($akteur)->get('/admin/objekte/'.$objekt->getKey())->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.support.record_viewed',
            'subject_id' => $objekt->getKey(),
        ]);
    }

    public function test_ein_abrechnungslauf_verlangt_eine_eigene_begruendung(): void
    {
        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->create();
        $akteur = $this->interneKennung();

        $this->actingAs($akteur)
            ->get('/admin/abrechnungen/'.$lauf->getKey())
            ->assertRedirect(route('admin.support.begruendung', [
                'entitaet' => 'abrechnung',
                'id' => $lauf->getKey(),
            ]));

        $this->actingAs($akteur)->post(
            '/admin/supportzugriff/abrechnung/'.$lauf->getKey(),
            ['grund' => 'Kunde meldet einen unklaren Verteilerschlüssel, Ticket 4713.'],
        );

        $antwort = $this->actingAs($akteur)->get('/admin/abrechnungen/'.$lauf->getKey());

        $antwort->assertOk();
        $antwort->assertSee('Abrechnungslauf');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.support.record_viewed',
            'subject_id' => $lauf->getKey(),
        ]);
    }

    public function test_eine_unbekannte_entitaet_wird_abgewiesen(): void
    {
        $this->actingAs($this->interneKennung())
            ->get('/admin/supportzugriff/rechnung/01HTESTKENNUNG0000000000')
            ->assertNotFound();
    }

    public function test_das_protokoll_zeigt_akteur_aktion_und_begruendung(): void
    {
        /** @var Organization $organisation */
        $organisation = Organization::factory()->create();
        $akteur = $this->interneKennung();
        $grund = 'Kunde meldet eine fehlende Mieterabrechnung, Ticket 4711.';

        $this->actingAs($akteur)->post(
            '/admin/supportzugriff/organisation/'.$organisation->getKey(),
            ['grund' => $grund],
        );

        $antwort = $this->actingAs($akteur)->get('/admin/protokoll');

        $antwort->assertOk();
        $antwort->assertSee('admin.support.access_granted');
        $antwort->assertSee($grund);
        $antwort->assertSee('127.0.0.0');
    }
}
