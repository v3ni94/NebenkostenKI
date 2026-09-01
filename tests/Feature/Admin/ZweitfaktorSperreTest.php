<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Application\Account\TwoFactorAuthentication;
use App\Domain\Security\TimeBasedOneTimePassword;
use App\Enums\UserStatus;
use App\Http\Middleware\RequireAdminTwoFactor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Zweiter Faktor als Voraussetzung einer Adminsitzung (Masterprompt 20).
 *
 * Der Zweitfaktor ist umgesetzt. Die Middleware sperrt den internen Bereich
 * deshalb nicht mehr pauschal, sondern leitet eine Adminkennung ohne aktiven
 * Faktor auf die Einrichtung. Nach der Einrichtung ist der Bereich in jeder
 * Umgebung nutzbar, auch in der Produktion.
 */
final class ZweitfaktorSperreTest extends AdminTestCase
{
    public function test_ohne_zweitfaktor_leitet_die_middleware_auf_die_einrichtung(): void
    {
        $nutzer = $this->interneKennung(zweitfaktor: false);

        $antwort = $this->actingAs($nutzer)->get('/admin');

        $antwort->assertRedirect(route('two-factor.setup'));
    }

    public function test_die_weiterleitung_erklaert_die_pflicht(): void
    {
        $nutzer = $this->interneKennung(zweitfaktor: false);

        $antwort = $this->actingAs($nutzer)->get('/admin');

        $antwort->assertSessionHas('status', RequireAdminTwoFactor::MELDUNG_ZWEITFAKTOR);
    }

    public function test_mit_bestaetigtem_zweitfaktor_bleibt_der_bereich_erreichbar(): void
    {
        $this->actingAs($this->interneKennung())->get('/admin')->assertOk();
    }

    public function test_in_der_produktion_wird_ohne_zweitfaktor_ebenfalls_nur_geleitet(): void
    {
        $nutzer = $this->interneKennung(zweitfaktor: false);

        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            $this->actingAs($nutzer)->get('/admin')->assertRedirect(route('two-factor.setup'));
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
        }
    }

    public function test_in_der_produktion_ist_der_bereich_mit_bestaetigtem_zweitfaktor_erreichbar(): void
    {
        $nutzer = $this->interneKennung();

        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            $this->actingAs($nutzer)->get('/admin')->assertOk();
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
        }
    }

    public function test_eine_sitzung_mit_offenem_faktor_wird_zur_codeeingabe_geleitet(): void
    {
        $nutzer = $this->interneKennung();

        $antwort = $this->actingAs($nutzer)
            ->withSession([TwoFactorAuthentication::SESSION_OFFENER_NUTZER => $nutzer->getKey()])
            ->get('/admin');

        $antwort->assertRedirect(route('two-factor.challenge'));
    }

    public function test_eine_gesperrte_kennung_erhaelt_keinen_zugang(): void
    {
        $nutzer = $this->interneKennung();
        $nutzer->forceFill(['status' => UserStatus::GESPERRT])->save();

        $request = Request::create('/admin', 'GET');
        $request->setUserResolver(fn (): User => $nutzer);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage(RequireAdminTwoFactor::MELDUNG_GESPERRT);

        $this->middleware()->handle(
            $request,
            fn (): \Symfony\Component\HttpFoundation\Response => new Response('ok'),
        );
    }

    public function test_ohne_angemeldeten_nutzer_sperrt_die_middleware(): void
    {
        $request = Request::create('/admin', 'GET');
        $request->setUserResolver(fn () => null);

        $this->expectException(HttpException::class);

        $this->middleware()->handle(
            $request,
            fn (): \Symfony\Component\HttpFoundation\Response => new Response('ok'),
        );
    }

    public function test_die_einrichtung_schaltet_den_adminbereich_frei(): void
    {
        $nutzer = $this->interneKennung(zweitfaktor: false);
        $dienst = app(TwoFactorAuthentication::class);
        $totp = new TimeBasedOneTimePassword;

        $geheimnis = $dienst->beginSetup($nutzer);

        $this->actingAs($nutzer)->get('/admin')->assertRedirect(route('two-factor.setup'));

        $this->actingAs($nutzer)
            ->post(route('two-factor.confirm'), ['code' => $totp->currentCode($geheimnis)])
            ->assertRedirect(route('two-factor.setup'));

        $this->actingAs($nutzer->refresh())->get('/admin')->assertOk();
    }

    private function middleware(): RequireAdminTwoFactor
    {
        return app(RequireAdminTwoFactor::class);
    }
}
