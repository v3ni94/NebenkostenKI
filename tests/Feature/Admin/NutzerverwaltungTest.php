<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/**
 * Nutzerverwaltung: Liste, Sperren, Entsperren, Passwort-Reset
 * (Masterprompt 20).
 */
final class NutzerverwaltungTest extends AdminTestCase
{
    public function test_die_liste_zeigt_konten_mit_status_und_interner_rolle(): void
    {
        $kunde = $this->kunde();

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/nutzer');

        $antwort->assertOk();
        $antwort->assertSee($kunde['user']->getAttribute('email'));
        $antwort->assertSee('Interne Rolle');
        $antwort->assertSee('Aktiv');
    }

    public function test_ohne_begruendung_wird_nicht_gesperrt(): void
    {
        $kunde = $this->kunde();

        $this->actingAs($this->interneKennung())
            ->post('/admin/nutzer/'.$kunde['user']->getKey().'/sperren', ['grund' => ''])
            ->assertSessionHasErrors('grund');

        self::assertSame(
            UserStatus::AKTIV,
            User::query()->findOrFail($kunde['user']->getKey())->getAttribute('status'),
        );
    }

    public function test_sperren_setzt_den_status_und_wird_protokolliert(): void
    {
        $kunde = $this->kunde();
        $grund = 'Missbrauchsverdacht nach Meldung, Ticket 4720.';

        $this->actingAs($this->interneKennung())
            ->post('/admin/nutzer/'.$kunde['user']->getKey().'/sperren', ['grund' => $grund])
            ->assertRedirect('/admin/nutzer');

        /** @var User $frisch */
        $frisch = User::query()->findOrFail($kunde['user']->getKey());

        self::assertSame(UserStatus::GESPERRT, $frisch->getAttribute('status'));
        self::assertNull($frisch->getAttribute('remember_token'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.user.locked',
            'subject_id' => $kunde['user']->getKey(),
            'reason' => $grund,
        ]);
    }

    public function test_eine_gesperrte_interne_kennung_erhaelt_keinen_zugang_mehr(): void
    {
        $interne = $this->interneKennung(AdminRole::SUPPORT);

        // Zunaechst ist der Zugang moeglich.
        $this->actingAs($interne)->get('/admin')->assertOk();

        $interne->forceFill(['status' => UserStatus::GESPERRT])->save();

        $this->actingAs(User::query()->findOrFail($interne->getKey()))
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_eine_zur_loeschung_vorgemerkte_kennung_erhaelt_keinen_zugang(): void
    {
        $interne = $this->interneKennung();
        $interne->forceFill(['status' => UserStatus::GELOESCHT])->save();

        $this->actingAs(User::query()->findOrFail($interne->getKey()))
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_entsperren_stellt_den_status_wieder_her(): void
    {
        $kunde = $this->kunde();
        $kunde['user']->forceFill(['status' => UserStatus::GESPERRT])->save();

        $this->actingAs($this->interneKennung())
            ->post('/admin/nutzer/'.$kunde['user']->getKey().'/entsperren', [
                'grund' => 'Sachverhalt geklärt, Sperre wird aufgehoben.',
            ])
            ->assertRedirect('/admin/nutzer');

        self::assertSame(
            UserStatus::AKTIV,
            User::query()->findOrFail($kunde['user']->getKey())->getAttribute('status'),
        );

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.user.unlocked',
        ]);
    }

    public function test_ein_unbestaetigtes_konto_bleibt_nach_dem_entsperren_unbestaetigt(): void
    {
        /** @var User $nutzer */
        $nutzer = User::factory()->unverified()->create(['status' => UserStatus::GESPERRT]);

        $this->actingAs($this->interneKennung())
            ->post('/admin/nutzer/'.$nutzer->getKey().'/entsperren', [
                'grund' => 'Sachverhalt geklärt, Sperre wird aufgehoben.',
            ]);

        self::assertSame(
            UserStatus::UNBESTAETIGT,
            User::query()->findOrFail($nutzer->getKey())->getAttribute('status'),
        );
    }

    public function test_die_eigene_kennung_kann_nicht_gesperrt_werden(): void
    {
        $interne = $this->interneKennung();

        $this->actingAs($interne)
            ->post('/admin/nutzer/'.$interne->getKey().'/sperren', [
                'grund' => 'Versehentlicher Versuch, Ticket 4721.',
            ])
            ->assertRedirect('/admin/nutzer');

        self::assertSame(
            UserStatus::AKTIV,
            User::query()->findOrFail($interne->getKey())->getAttribute('status'),
        );
    }

    public function test_der_passwort_reset_wird_ausgeloest_und_protokolliert(): void
    {
        Notification::fake();

        $kunde = $this->kunde();

        $this->actingAs($this->interneKennung())
            ->post('/admin/nutzer/'.$kunde['user']->getKey().'/passwort')
            ->assertRedirect('/admin/nutzer');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.user.password_reset_requested',
            'subject_id' => $kunde['user']->getKey(),
        ]);

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => $kunde['user']->getAttribute('email'),
        ]);
    }

    public function test_der_adminbereich_setzt_niemals_selbst_ein_passwort(): void
    {
        Notification::fake();

        $kunde = $this->kunde();
        $hashVorher = (string) $kunde['user']->getAttribute('password');

        $this->actingAs($this->interneKennung())
            ->post('/admin/nutzer/'.$kunde['user']->getKey().'/passwort');

        self::assertSame(
            $hashVorher,
            (string) User::query()->findOrFail($kunde['user']->getKey())->getAttribute('password'),
        );
    }
}
