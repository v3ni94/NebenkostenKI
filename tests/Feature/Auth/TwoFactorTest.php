<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Application\Account\TwoFactorAuthentication;
use App\Console\Commands\Admin\ResetTwoFactorCommand;
use App\Domain\Security\TimeBasedOneTimePassword;
use App\Enums\AdminRole;
use App\Enums\OrganizationRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Models\AdminRoleAssignment;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * TOTP-Zweitfaktor: Einrichtung, Anmeldung, Wiederherstellungscodes,
 * Abschaltung und die Adminpflicht (Masterprompt 8.1, 20).
 */
final class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private const string PASSWORT = 'sicheres-passwort-2026';

    private TimeBasedOneTimePassword $totp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->totp = new TimeBasedOneTimePassword;
    }

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
            'role' => OrganizationRole::OWNER,
            'joined_at' => now(),
        ]);

        return $nutzer;
    }

    private function dienst(): TwoFactorAuthentication
    {
        return app(TwoFactorAuthentication::class);
    }

    /**
     * Nutzer mit aktivem Zweitfaktor. Gibt Nutzer und Klartextcodes zurueck.
     *
     * @return array{user: User, secret: string, codes: list<string>}
     */
    private function mitZweitfaktor(?User $nutzer = null): array
    {
        $nutzer ??= $this->nutzer();

        $geheimnis = $this->dienst()->beginSetup($nutzer);
        $codes = $this->dienst()->confirm($nutzer, $this->totp->currentCode($geheimnis));

        self::assertIsArray($codes);

        return ['user' => $nutzer->refresh(), 'secret' => $geheimnis, 'codes' => $codes];
    }

    /**
     * Code des naechsten Zeitfensters.
     *
     * Der Einrichtungscode gilt nach der Aktivierung als verbraucht
     * (Replay-Schutz, RFC 6238 Abschnitt 5.2). Die Anmeldung im Test verwendet
     * deshalb das Folgefenster, das innerhalb der Toleranz liegt und einen
     * groesseren Zaehler traegt.
     */
    private function code(string $geheimnis): string
    {
        return $this->totp->codeAt($geheimnis, time() + TimeBasedOneTimePassword::ZEITFENSTER_SEKUNDEN);
    }

    // --- Einrichtung ---------------------------------------------------------

    public function test_die_einrichtungsseite_ist_fuer_angemeldete_nutzer_erreichbar(): void
    {
        $antwort = $this->actingAs($this->nutzer())->get(route('two-factor.setup'));

        $antwort->assertOk();
        $antwort->assertSee('Zwei-Faktor-Authentifizierung');
        $antwort->assertSee('Schlüssel erzeugen');
    }

    public function test_die_einrichtungsseite_ist_ohne_anmeldung_gesperrt(): void
    {
        $this->get(route('two-factor.setup'))->assertRedirect(route('login'));
    }

    public function test_der_start_erzeugt_ein_geheimnis_und_zeigt_uri_und_abtippbaren_schluessel(): void
    {
        $nutzer = $this->nutzer();

        $this->actingAs($nutzer)->post(route('two-factor.setup.start'))
            ->assertRedirect(route('two-factor.setup'));

        $nutzer->refresh();

        $geheimnis = $this->dienst()->secret($nutzer);
        self::assertIsString($geheimnis);
        self::assertNull($nutzer->getAttribute('two_factor_confirmed_at'));

        $antwort = $this->actingAs($nutzer)->get(route('two-factor.setup'));
        $antwort->assertOk();
        $antwort->assertSee('otpauth://totp/', false);
        $antwort->assertSee($this->totp->formatSecret($geheimnis));
        $antwort->assertSee('Einrichtung begonnen');
    }

    public function test_das_geheimnis_liegt_in_der_datenbank_verschluesselt(): void
    {
        $nutzer = $this->nutzer();
        $geheimnis = $this->dienst()->beginSetup($nutzer);

        /** @var object{two_factor_secret: string|null} $zeile */
        $zeile = DB::table('users')->where('id', $nutzer->getKey())->first();

        $rohwert = $zeile->two_factor_secret;

        self::assertIsString($rohwert);
        self::assertNotSame($geheimnis, $rohwert);
        self::assertStringNotContainsString($geheimnis, $rohwert);
        self::assertStringNotContainsString(substr($geheimnis, 0, 8), $rohwert);
        // Der Wert ist entschluesselbar und ergibt wieder das Geheimnis.
        self::assertSame($geheimnis, $this->dienst()->secret($nutzer->refresh()));
    }

    public function test_die_einrichtung_verlangt_einen_gueltigen_code(): void
    {
        $nutzer = $this->nutzer();
        $this->dienst()->beginSetup($nutzer);

        $antwort = $this->actingAs($nutzer)
            ->post(route('two-factor.confirm'), ['code' => '000000']);

        $antwort->assertRedirect(route('two-factor.setup'));
        $antwort->assertSessionHasErrors('code');

        self::assertNull($nutzer->refresh()->getAttribute('two_factor_confirmed_at'));
    }

    public function test_ein_gueltiger_code_aktiviert_den_faktor_und_zeigt_acht_wiederherstellungscodes(): void
    {
        $nutzer = $this->nutzer();
        $geheimnis = $this->dienst()->beginSetup($nutzer);

        $antwort = $this->actingAs($nutzer)
            ->post(route('two-factor.confirm'), ['code' => $this->code($geheimnis)]);

        $antwort->assertRedirect(route('two-factor.setup'));
        $antwort->assertSessionHas(TwoFactorAuthentication::SESSION_CODES);

        $nutzer->refresh();
        self::assertNotNull($nutzer->getAttribute('two_factor_confirmed_at'));
        self::assertSame(8, $this->dienst()->remainingRecoveryCodes($nutzer));

        /** @var list<string> $codes */
        $codes = session(TwoFactorAuthentication::SESSION_CODES);
        self::assertCount(8, $codes);

        $folgeseite = $this->actingAs($nutzer)->get(route('two-factor.setup'));
        $folgeseite->assertOk();
        $folgeseite->assertSee('Ihre Wiederherstellungscodes');
        $folgeseite->assertSee($codes[0]);
    }

    public function test_die_wiederherstellungscodes_liegen_nur_gehasht_in_der_datenbank(): void
    {
        $daten = $this->mitZweitfaktor();

        /** @var object{two_factor_recovery_codes: string|null} $zeile */
        $zeile = DB::table('users')->where('id', $daten['user']->getKey())->first();

        $rohwert = (string) $zeile->two_factor_recovery_codes;

        foreach ($daten['codes'] as $code) {
            self::assertStringNotContainsString($code, $rohwert);
        }
    }

    public function test_ein_bestaetigter_faktor_wird_durch_einen_erneuten_start_nicht_ueberschrieben(): void
    {
        $daten = $this->mitZweitfaktor();

        $this->actingAs($daten['user'])->post(route('two-factor.setup.start'));

        self::assertSame($daten['secret'], $this->dienst()->secret($daten['user']->refresh()));
        self::assertNotNull($daten['user']->getAttribute('two_factor_confirmed_at'));
    }

    // --- Anmeldung -----------------------------------------------------------

    public function test_die_anmeldung_verlangt_bei_aktivem_faktor_einen_zweiten_schritt(): void
    {
        $daten = $this->mitZweitfaktor();

        $antwort = $this->post(route('login'), [
            'email' => $daten['user']->getAttribute('email'),
            'password' => self::PASSWORT,
        ]);

        $antwort->assertRedirect(route('two-factor.challenge'));
        self::assertFalse(Auth::check());
    }

    public function test_eine_sitzung_ohne_bestaetigten_faktor_erreicht_keinen_geschuetzten_bereich(): void
    {
        $daten = $this->mitZweitfaktor();

        $this->post(route('login'), [
            'email' => $daten['user']->getAttribute('email'),
            'password' => self::PASSWORT,
        ])->assertRedirect(route('two-factor.challenge'));

        $this->get(route('portal.dashboard'))->assertRedirect(route('login'));
        $this->get(route('portal.konto.edit'))->assertRedirect(route('login'));
    }

    public function test_die_codeseite_ist_ohne_ersten_schritt_nicht_nutzbar(): void
    {
        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
        $this->post(route('two-factor.challenge.store'), ['code' => '123456'])
            ->assertRedirect(route('login'));
    }

    public function test_ein_gueltiger_code_schliesst_die_anmeldung_ab(): void
    {
        $daten = $this->mitZweitfaktor();

        $this->post(route('login'), [
            'email' => $daten['user']->getAttribute('email'),
            'password' => self::PASSWORT,
        ]);

        $this->get(route('two-factor.challenge'))->assertOk();

        $antwort = $this->post(route('two-factor.challenge.store'), [
            'code' => $this->code($daten['secret']),
        ]);

        $antwort->assertRedirect(route('portal.dashboard'));
        self::assertTrue(Auth::check());
        self::assertSame($daten['user']->getKey(), Auth::id());

        $this->get(route('portal.dashboard'))->assertOk();
    }

    public function test_der_kontostatus_wird_weiterhin_vor_dem_zweiten_schritt_geprueft(): void
    {
        $daten = $this->mitZweitfaktor($this->nutzer());
        $daten['user']->forceFill(['status' => UserStatus::GESPERRT])->save();

        $antwort = $this->post(route('login'), [
            'email' => $daten['user']->getAttribute('email'),
            'password' => self::PASSWORT,
        ]);

        $antwort->assertSessionHasErrors('email');
        self::assertFalse(Auth::check());
        $antwort->assertSessionMissing(TwoFactorAuthentication::SESSION_OFFENER_NUTZER);
    }

    public function test_ein_falscher_code_wird_abgelehnt(): void
    {
        $daten = $this->mitZweitfaktor();

        $this->post(route('login'), [
            'email' => $daten['user']->getAttribute('email'),
            'password' => self::PASSWORT,
        ]);

        $antwort = $this->post(route('two-factor.challenge.store'), ['code' => '000000']);

        $antwort->assertSessionHasErrors('code');
        self::assertFalse(Auth::check());
    }

    public function test_ein_code_aus_dem_uebernaechsten_zeitfenster_wird_bei_der_anmeldung_abgelehnt(): void
    {
        $daten = $this->mitZweitfaktor();

        $this->post(route('login'), [
            'email' => $daten['user']->getAttribute('email'),
            'password' => self::PASSWORT,
        ]);

        $veraltet = $this->totp->codeAt(
            $daten['secret'],
            time() - 2 * TimeBasedOneTimePassword::ZEITFENSTER_SEKUNDEN,
        );

        $this->post(route('two-factor.challenge.store'), ['code' => $veraltet])
            ->assertSessionHasErrors('code');

        self::assertFalse(Auth::check());
    }

    public function test_derselbe_code_wird_nicht_zweimal_angenommen(): void
    {
        $daten = $this->mitZweitfaktor();
        $code = $this->code($daten['secret']);

        $this->post(route('login'), [
            'email' => $daten['user']->getAttribute('email'),
            'password' => self::PASSWORT,
        ]);

        $this->post(route('two-factor.challenge.store'), ['code' => $code])
            ->assertRedirect(route('portal.dashboard'));

        self::assertTrue(Auth::check());

        // Zweite Anmeldung innerhalb des Toleranzfensters mit demselben Code.
        $this->post(route('logout'));

        $this->post(route('login'), [
            'email' => $daten['user']->getAttribute('email'),
            'password' => self::PASSWORT,
        ]);

        $this->post(route('two-factor.challenge.store'), ['code' => $code])
            ->assertSessionHasErrors('code');

        self::assertFalse(Auth::check());

        self::assertTrue(
            AuditLog::query()
                ->where('action', TwoFactorAuthentication::AKTION_FEHLSCHLAG)
                ->where('metadata->grund', 'wiederverwendung')
                ->exists()
        );
    }

    public function test_der_einrichtungscode_ist_bei_der_anmeldung_nicht_erneut_verwendbar(): void
    {
        $nutzer = $this->nutzer();
        $geheimnis = $this->dienst()->beginSetup($nutzer);
        $einrichtungscode = $this->totp->currentCode($geheimnis);

        $this->dienst()->confirm($nutzer, $einrichtungscode);

        self::assertFalse($this->dienst()->verify($nutzer->refresh(), $einrichtungscode));
    }

    public function test_ein_gesperrtes_konto_schliesst_den_zweiten_schritt_nicht_ab(): void
    {
        $daten = $this->mitZweitfaktor();

        $this->post(route('login'), [
            'email' => $daten['user']->getAttribute('email'),
            'password' => self::PASSWORT,
        ])->assertRedirect(route('two-factor.challenge'));

        // Sperre zwischen Passwort und Code, etwa durch den Adminbereich.
        $daten['user']->forceFill(['status' => UserStatus::GESPERRT])->save();

        $antwort = $this->post(route('two-factor.challenge.store'), [
            'code' => $this->code($daten['secret']),
        ]);

        $antwort->assertRedirect(route('login'));
        $antwort->assertSessionHasErrors('email');
        self::assertStringContainsString('gesperrt', (string) session('errors')?->first('email'));
        self::assertFalse(Auth::check());
        $antwort->assertSessionMissing(TwoFactorAuthentication::SESSION_OFFENER_NUTZER);

        // Auch die Codeseite ist fuer die gesperrte Kennung nicht mehr nutzbar.
        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
    }

    public function test_ein_vor_der_aktivierung_gemerktes_geraet_meldet_danach_nicht_mehr_an(): void
    {
        $nutzer = $this->nutzer(['remember_token' => str_repeat('a', 60)]);

        // Name des Cookies wie in Illuminate\Auth\SessionGuard::getRecallerName().
        $cookie = 'remember_web_'.sha1(SessionGuard::class);
        $wert = implode('|', [
            (string) $nutzer->getKey(),
            str_repeat('a', 60),
            (string) $nutzer->getAttribute('password'),
        ]);

        // Vor der Aktivierung meldet das Cookie an.
        $this->withCookie($cookie, $wert)->get(route('portal.dashboard'))->assertOk();

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $geheimnis = $this->dienst()->beginSetup($nutzer);
        $this->actingAs($nutzer)
            ->post(route('two-factor.confirm'), ['code' => $this->totp->currentCode($geheimnis)])
            ->assertRedirect(route('two-factor.setup'));

        $this->post(route('logout'));
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        self::assertNotSame(str_repeat('a', 60), $nutzer->refresh()->getAttribute('remember_token'));

        // Nach der Aktivierung ist das alte Merkmal wertlos.
        $this->withCookie($cookie, $wert)->get(route('portal.dashboard'))->assertRedirect(route('login'));
    }

    public function test_die_abschaltung_erneuert_das_merkmal_angemeldet_bleiben(): void
    {
        $daten = $this->mitZweitfaktor();
        $daten['user']->forceFill(['remember_token' => str_repeat('b', 60)])->save();

        $this->actingAs($daten['user'])
            ->post(route('two-factor.disable'), [
                'current_password' => self::PASSWORT,
                'code' => $this->code($daten['secret']),
            ])
            ->assertRedirect(route('two-factor.setup'));

        self::assertNotSame(str_repeat('b', 60), $daten['user']->refresh()->getAttribute('remember_token'));
    }

    public function test_die_codeeingabe_ist_eigenstaendig_ratenbegrenzt(): void
    {
        $daten = $this->mitZweitfaktor();

        $this->post(route('login'), [
            'email' => $daten['user']->getAttribute('email'),
            'password' => self::PASSWORT,
        ]);

        for ($versuch = 0; $versuch < TwoFactorChallengeController::VERSUCHE; $versuch++) {
            $this->post(route('two-factor.challenge.store'), ['code' => '000000'])
                ->assertSessionHasErrors('code');
        }

        // Auch der richtige Code wird jetzt nicht mehr angenommen.
        $antwort = $this->post(route('two-factor.challenge.store'), [
            'code' => $this->code($daten['secret']),
        ]);

        $antwort->assertSessionHasErrors('code');
        self::assertFalse(Auth::check());

        $fehler = session('errors');
        self::assertNotNull($fehler);
        self::assertStringContainsString('Zu viele Versuche', (string) $fehler->first('code'));

        RateLimiter::clear('zwei-faktor:'.$daten['user']->getKey().'|127.0.0.1');
    }

    public function test_die_anmeldung_kann_im_zweiten_schritt_abgebrochen_werden(): void
    {
        $daten = $this->mitZweitfaktor();

        $this->post(route('login'), [
            'email' => $daten['user']->getAttribute('email'),
            'password' => self::PASSWORT,
        ]);

        $this->post(route('two-factor.abort'))->assertRedirect(route('login'));

        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
    }

    public function test_ein_konto_ohne_zweitfaktor_meldet_sich_unveraendert_in_einem_schritt_an(): void
    {
        $nutzer = $this->nutzer();

        $antwort = $this->post(route('login'), [
            'email' => $nutzer->getAttribute('email'),
            'password' => self::PASSWORT,
        ]);

        $antwort->assertRedirect(route('portal.dashboard'));
        self::assertTrue(Auth::check());
    }

    // --- Wiederherstellungscodes --------------------------------------------

    public function test_ein_wiederherstellungscode_funktioniert_genau_einmal(): void
    {
        $daten = $this->mitZweitfaktor();
        $code = $daten['codes'][0];

        $this->post(route('login'), [
            'email' => $daten['user']->getAttribute('email'),
            'password' => self::PASSWORT,
        ]);

        $this->post(route('two-factor.challenge.store'), ['code' => $code])
            ->assertRedirect(route('portal.dashboard'));

        self::assertTrue(Auth::check());
        self::assertSame(7, $this->dienst()->remainingRecoveryCodes($daten['user']->refresh()));

        // Zweiter Anmeldeversuch mit demselben Code.
        $this->post(route('logout'));

        $this->post(route('login'), [
            'email' => $daten['user']->getAttribute('email'),
            'password' => self::PASSWORT,
        ]);

        $this->post(route('two-factor.challenge.store'), ['code' => $code])
            ->assertSessionHasErrors('code');

        self::assertFalse(Auth::check());
    }

    public function test_ein_wiederherstellungscode_wird_unabhaengig_von_schreibweise_erkannt(): void
    {
        $daten = $this->mitZweitfaktor();

        $eingabe = strtolower(str_replace('-', '', $daten['codes'][1]));

        self::assertTrue($this->dienst()->consumeRecoveryCode($daten['user'], $eingabe));
        self::assertSame(7, $this->dienst()->remainingRecoveryCodes($daten['user']->refresh()));
    }

    public function test_ein_unbekannter_wiederherstellungscode_verbraucht_keinen_gueltigen(): void
    {
        $daten = $this->mitZweitfaktor();

        self::assertFalse($this->dienst()->consumeRecoveryCode($daten['user'], 'ABCDE-FGHJK'));
        self::assertSame(8, $this->dienst()->remainingRecoveryCodes($daten['user']->refresh()));
    }

    // --- Abschaltung ---------------------------------------------------------

    public function test_die_abschaltung_verlangt_passwort_und_faktor(): void
    {
        $daten = $this->mitZweitfaktor();

        // Ohne Passwort
        $this->actingAs($daten['user'])
            ->post(route('two-factor.disable'), ['code' => $this->code($daten['secret'])])
            ->assertSessionHasErrors('current_password');

        // Falsches Passwort
        $this->actingAs($daten['user'])
            ->post(route('two-factor.disable'), [
                'current_password' => 'falsches-passwort-2026',
                'code' => $this->code($daten['secret']),
            ])
            ->assertSessionHasErrors('current_password');

        // Richtiges Passwort, falscher Code
        $this->actingAs($daten['user'])
            ->post(route('two-factor.disable'), [
                'current_password' => self::PASSWORT,
                'code' => '000000',
            ])
            ->assertSessionHasErrors('code');

        self::assertNotNull($daten['user']->refresh()->getAttribute('two_factor_confirmed_at'));
    }

    public function test_die_abschaltung_mit_passwort_und_code_entfernt_geheimnis_und_codes(): void
    {
        $daten = $this->mitZweitfaktor();

        $this->actingAs($daten['user'])
            ->post(route('two-factor.disable'), [
                'current_password' => self::PASSWORT,
                'code' => $this->code($daten['secret']),
            ])
            ->assertRedirect(route('two-factor.setup'));

        $daten['user']->refresh();

        self::assertNull($daten['user']->getAttribute('two_factor_confirmed_at'));
        self::assertNull($this->dienst()->secret($daten['user']));
        self::assertSame(0, $this->dienst()->remainingRecoveryCodes($daten['user']));
    }

    public function test_die_abschaltung_ist_auch_mit_einem_wiederherstellungscode_moeglich(): void
    {
        $daten = $this->mitZweitfaktor();

        $this->actingAs($daten['user'])
            ->post(route('two-factor.disable'), [
                'current_password' => self::PASSWORT,
                'code' => $daten['codes'][2],
            ])
            ->assertRedirect(route('two-factor.setup'));

        self::assertNull($daten['user']->refresh()->getAttribute('two_factor_confirmed_at'));
    }

    // --- Protokollierung -----------------------------------------------------

    public function test_die_protokolleintraege_enthalten_weder_geheimnis_noch_code(): void
    {
        $daten = $this->mitZweitfaktor();
        $code = $this->code($daten['secret']);

        $this->dienst()->verify($daten['user'], $code);
        $this->dienst()->verify($daten['user'], '000000');
        $this->dienst()->consumeRecoveryCode($daten['user'], $daten['codes'][3]);
        $this->dienst()->disable($daten['user']->refresh());

        $aktionen = AuditLog::query()->pluck('action')->all();

        foreach ([
            TwoFactorAuthentication::AKTION_AKTIVIERT,
            TwoFactorAuthentication::AKTION_ERFOLG,
            TwoFactorAuthentication::AKTION_FEHLSCHLAG,
            TwoFactorAuthentication::AKTION_WIEDERHERSTELLUNG,
            TwoFactorAuthentication::AKTION_DEAKTIVIERT,
        ] as $aktion) {
            self::assertContains($aktion, $aktionen);
        }

        $inhalt = AuditLog::query()->get()->toJson();

        self::assertStringNotContainsString($daten['secret'], $inhalt);
        self::assertStringNotContainsString($code, $inhalt);
        self::assertStringNotContainsString($daten['codes'][3], $inhalt);
    }

    // --- Adminpflicht --------------------------------------------------------

    private function adminKennung(bool $mitZweitfaktor): User
    {
        $nutzer = $this->nutzer();

        AdminRoleAssignment::query()->create([
            'user_id' => $nutzer->getKey(),
            'role' => AdminRole::ADMIN,
            'granted_at' => now(),
        ]);

        if ($mitZweitfaktor) {
            $this->mitZweitfaktor($nutzer);
        }

        return $nutzer->refresh();
    }

    public function test_eine_adminkennung_ohne_zweitfaktor_wird_auf_die_einrichtung_geleitet(): void
    {
        $antwort = $this->actingAs($this->adminKennung(false))->get('/admin');

        $antwort->assertRedirect(route('two-factor.setup'));
    }

    public function test_eine_adminkennung_ohne_zweitfaktor_wird_auch_in_der_produktion_geleitet_und_nicht_gesperrt(): void
    {
        $nutzer = $this->adminKennung(false);

        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            $this->actingAs($nutzer)->get('/admin')->assertRedirect(route('two-factor.setup'));
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
        }
    }

    public function test_der_adminbereich_ist_nach_der_einrichtung_auch_in_der_produktion_erreichbar(): void
    {
        $nutzer = $this->adminKennung(true);

        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            $this->actingAs($nutzer)->get('/admin')->assertOk();
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
        }
    }

    public function test_eine_adminsitzung_mit_offenem_faktor_wird_zur_codeeingabe_geleitet(): void
    {
        $nutzer = $this->adminKennung(true);

        $antwort = $this->actingAs($nutzer)
            ->withSession([TwoFactorAuthentication::SESSION_OFFENER_NUTZER => $nutzer->getKey()])
            ->get('/admin');

        $antwort->assertRedirect(route('two-factor.challenge'));
    }

    public function test_eine_cookie_anmeldung_oeffnet_den_adminbereich_erst_nach_erneutem_codenachweis(): void
    {
        $nutzer = $this->adminKennung(true);
        $daten = ['secret' => $this->dienst()->secret($nutzer)];
        self::assertIsString($daten['secret']);

        // Remember-Cookie, wie es nach einer vollstaendigen Anmeldung mit
        // "angemeldet bleiben" ausgestellt wird.
        $nutzer->forceFill(['remember_token' => str_repeat('c', 60)])->save();

        $cookie = 'remember_web_'.sha1(SessionGuard::class);
        $wert = implode('|', [
            (string) $nutzer->getKey(),
            str_repeat('c', 60),
            (string) $nutzer->getAttribute('password'),
        ]);

        // Das Cookie meldet an, der Kundenbereich ist erreichbar.
        $this->withCookie($cookie, $wert)->get(route('portal.dashboard'))->assertOk();
        self::assertTrue(Auth::viaRemember());

        // Der Adminbereich verlangt in dieser Sitzung den Codenachweis.
        $this->withCookie($cookie, $wert)->get('/admin')->assertRedirect(route('two-factor.challenge'));

        $this->withCookie($cookie, $wert)->get(route('two-factor.challenge'))->assertOk();

        $this->withCookie($cookie, $wert)->post(route('two-factor.challenge.store'), [
            'code' => $this->code($daten['secret']),
        ])->assertRedirect();

        self::assertTrue(Auth::check());
        self::assertSame($nutzer->getKey(), Auth::id());

        // Nach dem Nachweis ist der Adminbereich in derselben Sitzung offen.
        $this->withCookie($cookie, $wert)->get('/admin')->assertOk();
    }

    public function test_eine_regulaere_anmeldung_mit_code_oeffnet_den_adminbereich_unmittelbar(): void
    {
        $nutzer = $this->adminKennung(true);
        $geheimnis = $this->dienst()->secret($nutzer);
        self::assertIsString($geheimnis);

        $this->post(route('login'), [
            'email' => $nutzer->getAttribute('email'),
            'password' => self::PASSWORT,
            'remember' => '1',
        ])->assertRedirect(route('two-factor.challenge'));

        $this->post(route('two-factor.challenge.store'), ['code' => $this->code($geheimnis)])
            ->assertRedirect();

        $this->get('/admin')->assertOk();
    }

    // --- Notfall ueber die Konsole -------------------------------------------

    public function test_der_notfallbefehl_setzt_den_zweitfaktor_einer_adminkennung_zurueck_und_protokolliert(): void
    {
        $nutzer = $this->adminKennung(true);
        $nutzer->forceFill(['remember_token' => str_repeat('d', 60)])->save();

        $this->artisan('smartabrechnen:admin:reset-2fa', [
            '--email' => $nutzer->getAttribute('email'),
            '--grund' => 'Telefon verloren, keine Wiederherstellungscodes, Ticket 4711.',
            '--bestaetigt-von' => 'Zweite Person Beispiel',
            '--bestaetigt' => true,
        ])->assertSuccessful();

        $nutzer->refresh();

        self::assertNull($nutzer->getAttribute('two_factor_confirmed_at'));
        self::assertNull($this->dienst()->secret($nutzer));
        self::assertSame(0, $this->dienst()->remainingRecoveryCodes($nutzer));
        self::assertNotSame(str_repeat('d', 60), $nutzer->getAttribute('remember_token'));

        /** @var AuditLog $eintrag */
        $eintrag = AuditLog::query()->where('action', ResetTwoFactorCommand::AKTION)->firstOrFail();

        self::assertSame($nutzer->getKey(), $eintrag->getAttribute('subject_id'));
        self::assertNull($eintrag->getAttribute('actor_user_id'));
        self::assertSame('Telefon verloren, keine Wiederherstellungscodes, Ticket 4711.', $eintrag->getAttribute('reason'));
        self::assertSame('konsole', $eintrag->getAttribute('metadata')['kanal'] ?? null);
        self::assertSame('Zweite Person Beispiel', $eintrag->getAttribute('metadata')['bestaetigt_von'] ?? null);
        self::assertTrue(AuditLog::query()->where('action', TwoFactorAuthentication::AKTION_ZURUECKGESETZT)->exists());

        // Die naechste Anmeldung ist einstufig; der Adminbereich fuehrt zur
        // Einrichtung eines neuen Faktors.
        $this->post(route('login'), [
            'email' => $nutzer->getAttribute('email'),
            'password' => self::PASSWORT,
        ])->assertRedirect();

        self::assertTrue(Auth::check());
        $this->get('/admin')->assertRedirect(route('two-factor.setup'));
    }

    public function test_der_notfallbefehl_verlangt_begruendung_und_zweite_person(): void
    {
        $nutzer = $this->adminKennung(true);

        $this->artisan('smartabrechnen:admin:reset-2fa', [
            '--email' => $nutzer->getAttribute('email'),
            '--bestaetigt-von' => 'Zweite Person Beispiel',
            '--bestaetigt' => true,
        ])->assertFailed();

        $this->artisan('smartabrechnen:admin:reset-2fa', [
            '--email' => $nutzer->getAttribute('email'),
            '--grund' => 'Telefon verloren, keine Wiederherstellungscodes, Ticket 4711.',
            '--bestaetigt' => true,
        ])->assertFailed();

        self::assertNotNull($nutzer->refresh()->getAttribute('two_factor_confirmed_at'));
        self::assertFalse(AuditLog::query()->where('action', ResetTwoFactorCommand::AKTION)->exists());
    }

    public function test_der_notfallbefehl_fragt_ohne_bestaetigung_nach_und_bricht_bei_nein_ab(): void
    {
        $nutzer = $this->adminKennung(true);

        $this->artisan('smartabrechnen:admin:reset-2fa', [
            '--email' => $nutzer->getAttribute('email'),
            '--grund' => 'Telefon verloren, keine Wiederherstellungscodes, Ticket 4711.',
            '--bestaetigt-von' => 'Zweite Person Beispiel',
        ])
            ->expectsConfirmation('Identitaet geprueft und Vorgang im Vier-Augen-Prinzip bestaetigt. Fortfahren?', 'no')
            ->assertFailed();

        self::assertNotNull($nutzer->refresh()->getAttribute('two_factor_confirmed_at'));
    }

    public function test_der_notfallbefehl_wirkt_nicht_auf_kundenkonten(): void
    {
        $daten = $this->mitZweitfaktor();

        $this->artisan('smartabrechnen:admin:reset-2fa', [
            '--email' => $daten['user']->getAttribute('email'),
            '--grund' => 'Telefon verloren, keine Wiederherstellungscodes, Ticket 4711.',
            '--bestaetigt-von' => 'Zweite Person Beispiel',
            '--bestaetigt' => true,
        ])->assertFailed();

        self::assertNotNull($daten['user']->refresh()->getAttribute('two_factor_confirmed_at'));

        $this->artisan('smartabrechnen:admin:reset-2fa', [
            '--email' => 'unbekannt@beispiel.invalid',
            '--grund' => 'Telefon verloren, keine Wiederherstellungscodes, Ticket 4711.',
            '--bestaetigt-von' => 'Zweite Person Beispiel',
            '--bestaetigt' => true,
        ])->assertFailed();
    }

    public function test_eine_adminkennung_richtet_den_faktor_ueber_die_einrichtungsseite_ein(): void
    {
        $nutzer = $this->adminKennung(false);

        $this->actingAs($nutzer)->post(route('two-factor.setup.start'));

        $geheimnis = $this->dienst()->secret($nutzer->refresh());
        self::assertIsString($geheimnis);

        $this->actingAs($nutzer)
            ->post(route('two-factor.confirm'), ['code' => $this->code($geheimnis)])
            ->assertRedirect(route('two-factor.setup'));

        $this->actingAs($nutzer->refresh())->get('/admin')->assertOk();
    }
}
