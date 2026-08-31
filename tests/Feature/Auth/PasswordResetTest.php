<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Passwort-Reset mit kurzlebigem Einmal-Token.
 */
final class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function nutzer(): User
    {
        /** @var User $nutzer */
        $nutzer = User::factory()->create([
            'email' => 'reset@beispiel.invalid',
            'password' => Hash::make('altes-passwort-2026'),
        ]);

        return $nutzer;
    }

    public function test_formular_ist_erreichbar(): void
    {
        $antwort = $this->get(route('password.request'));

        $antwort->assertOk();
        $antwort->assertSee('Passwort zurücksetzen');
    }

    public function test_link_wird_an_ein_bestehendes_konto_versendet(): void
    {
        Notification::fake();
        $nutzer = $this->nutzer();

        $antwort = $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'reset@beispiel.invalid']);

        $antwort->assertRedirect(route('password.request'));
        $antwort->assertSessionHas('status');
        Notification::assertSentTo($nutzer, ResetPasswordLink::class);
    }

    public function test_antwort_ist_bei_unbekannter_adresse_identisch(): void
    {
        Notification::fake();
        $this->nutzer();

        $bekannt = $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'reset@beispiel.invalid']);
        $meldungBekannt = session('status');

        $this->flushSession();

        $unbekannt = $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'unbekannt@beispiel.invalid']);
        $meldungUnbekannt = session('status');

        $bekannt->assertRedirect(route('password.request'));
        $unbekannt->assertRedirect(route('password.request'));
        self::assertSame($meldungBekannt, $meldungUnbekannt);
        self::assertIsString($meldungUnbekannt);
        self::assertStringContainsString('Falls zu dieser E-Mail-Adresse ein Konto besteht', $meldungUnbekannt);

        Notification::assertSentToTimes($this->nutzerAusDatenbank(), ResetPasswordLink::class, 1);
    }

    public function test_gueltiges_token_setzt_das_passwort_neu(): void
    {
        $nutzer = $this->nutzer();
        $token = Password::broker()->createToken($nutzer);

        $antwort = $this->post(route('password.update'), [
            'token' => $token,
            'email' => 'reset@beispiel.invalid',
            'password' => 'ganz-neues-passwort-2026',
            'password_confirmation' => 'ganz-neues-passwort-2026',
        ]);

        $antwort->assertRedirect(route('login'));
        $antwort->assertSessionHas('status');

        $frisch = $nutzer->fresh();
        self::assertInstanceOf(User::class, $frisch);
        self::assertTrue(Hash::check('ganz-neues-passwort-2026', (string) $frisch->getAttribute('password')));
    }

    public function test_token_laesst_sich_nur_einmal_verwenden(): void
    {
        $nutzer = $this->nutzer();
        $token = Password::broker()->createToken($nutzer);

        $daten = [
            'token' => $token,
            'email' => 'reset@beispiel.invalid',
            'password' => 'ganz-neues-passwort-2026',
            'password_confirmation' => 'ganz-neues-passwort-2026',
        ];

        $this->post(route('password.update'), $daten);
        $zweiter = $this->from(route('password.reset', ['token' => $token]))
            ->post(route('password.update'), $daten);

        $zweiter->assertSessionHasErrors('email');
    }

    public function test_abgelaufenes_token_wird_abgelehnt(): void
    {
        $nutzer = $this->nutzer();
        $token = Password::broker()->createToken($nutzer);

        $minuten = (int) config('auth.passwords.users.expire');
        $this->travel($minuten + 5)->minutes();

        $antwort = $this->from(route('password.reset', ['token' => $token]))
            ->post(route('password.update'), [
                'token' => $token,
                'email' => 'reset@beispiel.invalid',
                'password' => 'ganz-neues-passwort-2026',
                'password_confirmation' => 'ganz-neues-passwort-2026',
            ]);

        $antwort->assertSessionHasErrors('email');

        $frisch = $nutzer->fresh();
        self::assertInstanceOf(User::class, $frisch);
        self::assertTrue(Hash::check('altes-passwort-2026', (string) $frisch->getAttribute('password')));
    }

    public function test_falsches_token_wird_abgelehnt(): void
    {
        $nutzer = $this->nutzer();
        Password::broker()->createToken($nutzer);

        $antwort = $this->from(route('password.reset', ['token' => 'erfunden']))
            ->post(route('password.update'), [
                'token' => 'erfunden',
                'email' => 'reset@beispiel.invalid',
                'password' => 'ganz-neues-passwort-2026',
                'password_confirmation' => 'ganz-neues-passwort-2026',
            ]);

        $antwort->assertSessionHasErrors('email');
    }

    public function test_zuruecksetzen_schreibt_einen_revisionseintrag(): void
    {
        $nutzer = $this->nutzer();
        $token = Password::broker()->createToken($nutzer);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => 'reset@beispiel.invalid',
            'password' => 'ganz-neues-passwort-2026',
            'password_confirmation' => 'ganz-neues-passwort-2026',
        ]);

        self::assertTrue(
            AuditLog::query()
                ->where('action', 'account.password_reset')
                ->where('actor_user_id', $nutzer->getKey())
                ->exists()
        );
    }

    public function test_zu_kurzes_neues_passwort_wird_abgelehnt(): void
    {
        $nutzer = $this->nutzer();
        $token = Password::broker()->createToken($nutzer);

        $antwort = $this->from(route('password.reset', ['token' => $token]))
            ->post(route('password.update'), [
                'token' => $token,
                'email' => 'reset@beispiel.invalid',
                'password' => 'kurz1',
                'password_confirmation' => 'kurz1',
            ]);

        $antwort->assertSessionHasErrors('password');
    }

    public function test_gueltigkeit_des_tokens_ist_kurzlebig_konfiguriert(): void
    {
        self::assertLessThanOrEqual(60, (int) config('auth.passwords.users.expire'));
    }

    public function test_resetmail_enthaelt_html_und_klartext(): void
    {
        $nutzer = $this->nutzer();

        $nachricht = (new ResetPasswordLink('test-token'))->toMail($nutzer);

        self::assertSame('Passwort für Smart Abrechnen zurücksetzen', $nachricht->subject);
        self::assertSame(
            ['emails.auth.passwort-zuruecksetzen', 'emails.auth.passwort-zuruecksetzen-text'],
            $nachricht->view
        );
    }

    private function nutzerAusDatenbank(): User
    {
        /** @var User $nutzer */
        $nutzer = User::query()->where('email', 'reset@beispiel.invalid')->firstOrFail();

        return $nutzer;
    }
}
