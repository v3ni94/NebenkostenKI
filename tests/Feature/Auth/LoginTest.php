<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Anmeldung, Ratenbegrenzung und Schutz vor Kontoerkennung.
 */
final class LoginTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORT = 'sicheres-passwort-2026';

    /**
     * @param  array<string, mixed>  $abweichungen
     */
    private function nutzer(array $abweichungen = []): User
    {
        /** @var User $nutzer */
        $nutzer = User::factory()->create(array_merge([
            'email' => 'anna.muster@beispiel.invalid',
            'password' => Hash::make(self::PASSWORT),
        ], $abweichungen));

        $organisation = Organization::factory()->create();

        OrganizationUser::query()->create([
            'organization_id' => $organisation->getKey(),
            'user_id' => $nutzer->getKey(),
            'role' => 'OWNER',
            'joined_at' => now(),
        ]);

        return $nutzer;
    }

    public function test_gesperrtes_konto_wird_trotz_richtigem_passwort_nicht_angemeldet(): void
    {
        $nutzer = $this->nutzer(['status' => UserStatus::GESPERRT]);

        $antwort = $this->from(route('login'))->post(route('login'), [
            'email' => $nutzer->getAttribute('email'),
            'password' => self::PASSWORT,
        ]);

        $antwort->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertStringContainsString(
            'gesperrt',
            (string) session('errors')?->first('email'),
        );
    }

    public function test_zur_loeschung_vorgemerktes_konto_wird_nicht_angemeldet(): void
    {
        $nutzer = $this->nutzer(['status' => UserStatus::GELOESCHT]);

        $this->post(route('login'), [
            'email' => $nutzer->getAttribute('email'),
            'password' => self::PASSWORT,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_unbestaetigtes_konto_darf_sich_anmelden(): void
    {
        // Konto und Entwuerfe sind kostenlos und ohne Huerde nutzbar. Die
        // E-Mail-Bestaetigung ist erst vor Zahlung und finalem Download
        // verbindlich (Masterprompt 8.1).
        $nutzer = $this->nutzer([
            'status' => UserStatus::UNBESTAETIGT,
            'email_verified_at' => null,
        ]);

        $this->post(route('login'), [
            'email' => $nutzer->getAttribute('email'),
            'password' => self::PASSWORT,
        ]);

        $this->assertAuthenticatedAs($nutzer);
    }

    public function test_anmeldeformular_ist_erreichbar(): void
    {
        $antwort = $this->get(route('login'));

        $antwort->assertOk();
        $antwort->assertSee('Anmelden');
        $antwort->assertSee('Passwort vergessen');
    }

    public function test_anmeldung_mit_richtigen_daten_gelingt(): void
    {
        $nutzer = $this->nutzer();

        $antwort = $this->post(route('login'), [
            'email' => 'anna.muster@beispiel.invalid',
            'password' => self::PASSWORT,
        ]);

        $antwort->assertRedirect(route('portal.dashboard'));
        $this->assertAuthenticatedAs($nutzer);
    }

    public function test_anmeldung_erneuert_die_sitzungskennung(): void
    {
        $this->nutzer();

        $this->get(route('login'));
        $vorher = session()->getId();

        $this->post(route('login'), [
            'email' => 'anna.muster@beispiel.invalid',
            'password' => self::PASSWORT,
        ]);

        self::assertNotSame($vorher, session()->getId());
    }

    public function test_anmeldung_setzt_den_zeitpunkt_und_schreibt_einen_revisionseintrag(): void
    {
        $nutzer = $this->nutzer();

        $this->post(route('login'), [
            'email' => 'anna.muster@beispiel.invalid',
            'password' => self::PASSWORT,
        ]);

        self::assertNotNull($nutzer->fresh()?->getAttribute('last_login_at'));
        self::assertTrue(
            AuditLog::query()
                ->where('action', 'account.logged_in')
                ->where('actor_user_id', $nutzer->getKey())
                ->exists()
        );
    }

    public function test_falsches_passwort_meldet_nicht_an(): void
    {
        $this->nutzer();

        $antwort = $this->post(route('login'), [
            'email' => 'anna.muster@beispiel.invalid',
            'password' => 'falsches-passwort-2026',
        ]);

        $antwort->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_fehlermeldung_verraet_nicht_ob_die_adresse_existiert(): void
    {
        $this->nutzer();

        $bekannt = $this->post(route('login'), [
            'email' => 'anna.muster@beispiel.invalid',
            'password' => 'falsches-passwort-2026',
        ]);

        $meldungBekannt = session('errors')?->get('email') ?? [];

        $this->flushSession();
        RateLimiter::clear('anmeldung:anna.muster@beispiel.invalid|127.0.0.1');

        $unbekannt = $this->post(route('login'), [
            'email' => 'gibt.es.nicht@beispiel.invalid',
            'password' => 'falsches-passwort-2026',
        ]);

        $meldungUnbekannt = session('errors')?->get('email') ?? [];

        $bekannt->assertSessionHasErrors('email');
        $unbekannt->assertSessionHasErrors('email');

        // Beide Wege liefern exakt denselben Text.
        self::assertSame($meldungBekannt, $meldungUnbekannt);
        self::assertStringContainsString(
            'Die Zugangsdaten sind nicht richtig.',
            implode(' ', $meldungBekannt)
        );
    }

    public function test_zu_viele_fehlversuche_fuehren_zu_einer_sperre_mit_deutschem_hinweis(): void
    {
        $this->nutzer();

        for ($versuch = 0; $versuch < LoginRequest::VERSUCHE_JE_KONTO; $versuch++) {
            $this->post(route('login'), [
                'email' => 'anna.muster@beispiel.invalid',
                'password' => 'falsches-passwort-2026',
            ]);
        }

        // Der naechste Versuch wird gesperrt, auch mit dem richtigen Passwort.
        $antwort = $this->post(route('login'), [
            'email' => 'anna.muster@beispiel.invalid',
            'password' => self::PASSWORT,
        ]);

        $antwort->assertSessionHasErrors('email');
        $this->assertGuest();

        $meldung = implode(' ', session('errors')?->get('email') ?? []);
        self::assertStringContainsString('Zu viele Anmeldeversuche', $meldung);
        self::assertStringContainsString('erneut', $meldung);
    }

    public function test_erfolgreiche_anmeldung_loescht_den_fehlversuchszaehler(): void
    {
        $this->nutzer();

        $this->post(route('login'), [
            'email' => 'anna.muster@beispiel.invalid',
            'password' => 'falsches-passwort-2026',
        ]);

        $this->post(route('login'), [
            'email' => 'anna.muster@beispiel.invalid',
            'password' => self::PASSWORT,
        ]);

        $this->assertAuthenticated();
        self::assertSame(
            0,
            RateLimiter::attempts('anmeldung:anna.muster@beispiel.invalid|127.0.0.1')
        );
    }

    public function test_abmeldung_beendet_die_sitzung_und_erneuert_die_kennung(): void
    {
        $nutzer = $this->nutzer();

        $this->actingAs($nutzer);
        $this->get(route('portal.dashboard'));
        $vorher = session()->getId();

        $antwort = $this->post(route('logout'));

        $antwort->assertRedirect(route('site.home'));
        $this->assertGuest();
        self::assertNotSame($vorher, session()->getId());
    }

    public function test_abmeldung_schreibt_einen_revisionseintrag(): void
    {
        $nutzer = $this->nutzer();

        $this->actingAs($nutzer)->post(route('logout'));

        self::assertTrue(
            AuditLog::query()
                ->where('action', 'account.logged_out')
                ->where('actor_user_id', $nutzer->getKey())
                ->exists()
        );
    }

    public function test_gast_wird_von_der_anwendung_auf_die_anmeldung_geleitet(): void
    {
        $antwort = $this->get(route('portal.dashboard'));

        $antwort->assertRedirect(route('login'));
        self::assertFalse(Auth::check());
    }
}
