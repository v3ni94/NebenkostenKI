<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Http\Middleware\RequireAdminTwoFactor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Zweiter Faktor als Voraussetzung einer Adminsitzung (Masterprompt 20).
 *
 * Der Zweitfaktor selbst ist noch nicht umgesetzt. Die Middleware ist deshalb
 * konservativ: In der Produktionsumgebung sperrt ein fehlendes
 * Bestaetigungsmerkmal den Adminbereich, in local und testing bleibt er
 * nutzbar und zeigt einen sichtbaren Hinweis.
 */
final class ZweitfaktorSperreTest extends AdminTestCase
{
    public function test_in_der_testumgebung_bleibt_der_bereich_ohne_zweitfaktor_nutzbar(): void
    {
        $nutzer = $this->interneKennung(zweitfaktor: false);

        $this->actingAs($nutzer)->get('/admin')->assertOk();
    }

    public function test_der_fehlende_zweitfaktor_wird_sichtbar_ausgewiesen(): void
    {
        $antwort = $this->actingAs($this->interneKennung(zweitfaktor: false))->get('/admin');

        $antwort->assertOk();
        $antwort->assertSee('Zweiter Faktor nicht eingerichtet');
        $antwort->assertSee('vor dem Livegang einzurichten', false);
    }

    public function test_mit_bestaetigtem_zweitfaktor_erscheint_kein_hinweis(): void
    {
        $antwort = $this->actingAs($this->interneKennung())->get('/admin');

        $antwort->assertOk();
        $antwort->assertDontSee('Zweiter Faktor nicht eingerichtet');
    }

    public function test_in_der_produktion_sperrt_ein_fehlender_zweitfaktor_den_bereich(): void
    {
        $nutzer = $this->interneKennung(zweitfaktor: false);

        $request = Request::create('/admin', 'GET');
        $request->setUserResolver(fn () => $nutzer);

        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            $middleware = new RequireAdminTwoFactor;

            $this->expectException(HttpException::class);
            $this->expectExceptionMessage(RequireAdminTwoFactor::MELDUNG_ZWEITFAKTOR);

            $middleware->handle($request, fn (): \Symfony\Component\HttpFoundation\Response => new Response('ok'));
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
        }
    }

    public function test_in_der_produktion_bleibt_der_bereich_mit_bestaetigtem_zweitfaktor_erreichbar(): void
    {
        $nutzer = $this->interneKennung();

        $request = Request::create('/admin', 'GET');
        $request->setUserResolver(fn () => $nutzer);

        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            $middleware = new RequireAdminTwoFactor;

            $antwort = $middleware->handle(
                $request,
                fn (): \Symfony\Component\HttpFoundation\Response => new Response('ok'),
            );

            self::assertSame('ok', $antwort->getContent());
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
        }
    }

    public function test_ohne_angemeldeten_nutzer_sperrt_die_middleware(): void
    {
        $request = Request::create('/admin', 'GET');
        $request->setUserResolver(fn () => null);

        $this->expectException(HttpException::class);

        (new RequireAdminTwoFactor)->handle(
            $request,
            fn (): \Symfony\Component\HttpFoundation\Response => new Response('ok'),
        );
    }
}
