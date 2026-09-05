<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Enums\UserStatus;
use App\Mail\ZweitfaktorZurueckgesetztMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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

    public function test_der_zweitfaktor_eines_kunden_wird_mit_begruendung_zurueckgesetzt_und_protokolliert(): void
    {
        Mail::fake();

        $kunde = $this->kunde();
        $kunde['user']->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['hash-eins'],
            'two_factor_last_counter' => 12345,
            'remember_token' => 'altes-merkmal',
        ])->save();

        DB::table('sessions')->insert([
            'id' => 'sitzung-des-kunden',
            'user_id' => $kunde['user']->getKey(),
            'payload' => 'leer',
            'last_activity' => time(),
        ]);

        $grund = 'Identität per Rückruf und Rechnungsnummer geprüft, Ticket 4730.';

        $this->actingAs($this->interneKennung())
            ->post('/admin/nutzer/'.$kunde['user']->getKey().'/zweitfaktor-zuruecksetzen', ['grund' => $grund])
            ->assertRedirect('/admin/nutzer');

        /** @var User $frisch */
        $frisch = User::query()->findOrFail($kunde['user']->getKey());

        self::assertNull($frisch->getAttribute('two_factor_secret'));
        self::assertNull($frisch->getAttribute('two_factor_confirmed_at'));
        self::assertNull($frisch->getAttribute('two_factor_recovery_codes'));
        self::assertNull($frisch->getAttribute('two_factor_last_counter'));
        self::assertNotSame('altes-merkmal', $frisch->getAttribute('remember_token'));

        $this->assertDatabaseMissing('sessions', ['user_id' => $kunde['user']->getKey()]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.user.two_factor_reset',
            'subject_id' => $kunde['user']->getKey(),
            'reason' => $grund,
        ]);

        Mail::assertSent(ZweitfaktorZurueckgesetztMail::class, function (ZweitfaktorZurueckgesetztMail $mail) use ($kunde): bool {
            return $mail->hasTo((string) $kunde['user']->getAttribute('email')) && $mail->istKritisch();
        });
    }

    public function test_der_zweitfaktor_wird_ohne_begruendung_nicht_zurueckgesetzt(): void
    {
        $kunde = $this->kunde();
        $kunde['user']->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($this->interneKennung())
            ->post('/admin/nutzer/'.$kunde['user']->getKey().'/zweitfaktor-zuruecksetzen', ['grund' => 'kurz'])
            ->assertSessionHasErrors('grund');

        self::assertNotNull(
            User::query()->findOrFail($kunde['user']->getKey())->getAttribute('two_factor_confirmed_at'),
        );
    }

    public function test_die_nutzerliste_bietet_den_zweitfaktor_reset_nur_bei_aktivem_faktor(): void
    {
        $kunde = $this->kunde();
        $kunde['user']->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
        ])->save();

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/nutzer');

        $antwort->assertOk();
        $antwort->assertSee('Zweitfaktor zurücksetzen');
        $antwort->assertSee(route('admin.nutzer.zweitfaktor', $kunde['user']), false);
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
