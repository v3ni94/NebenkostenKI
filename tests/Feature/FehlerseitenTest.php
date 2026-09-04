<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Eigene deutsche Fehlerseiten auf dem oeffentlichen Layout (Masterprompt 12,
 * ARCHITECTURE.md Grundsatz 11).
 *
 * Ohne eigene Vorlagen liefert das Framework englische Standardseiten ohne
 * Navigation, ohne Rueckweg und ohne Betreiberangaben.
 */
final class FehlerseitenTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{int}>
     */
    public static function statuscodes(): array
    {
        return [
            'Anmeldung erforderlich' => [401],
            'kein Zugriff' => [403],
            'nicht gefunden' => [404],
            'Verfahren nicht erlaubt' => [405],
            'Formular abgelaufen' => [419],
            'zu viele Anfragen' => [429],
            'Serverfehler' => [500],
            'Wartung' => [503],
        ];
    }

    #[DataProvider('statuscodes')]
    public function test_fuer_jeden_statuscode_liegt_eine_eigene_vorlage_vor(int $code): void
    {
        self::assertFileExists(resource_path('views/errors/'.$code.'.blade.php'));
    }

    public function test_unbekannter_pfad_zeigt_eine_deutsche_seite_mit_betreiberangaben(): void
    {
        $antwort = $this->get('/diese-seite-gibt-es-nicht');

        $antwort->assertNotFound();
        $this->assertFehlerseite($antwort, 'Seite nicht gefunden');
        $antwort->assertDontSee('Not Found');
    }

    public function test_nicht_erlaubtes_verfahren_zeigt_eine_deutsche_seite(): void
    {
        $antwort = $this->get('/webhooks/stripe');

        $antwort->assertStatus(405);
        $this->assertFehlerseite($antwort, 'Diese Anfrage ist hier nicht möglich');
        $antwort->assertDontSee('Oops! An Error Occurred');
        $antwort->assertDontSee('Method Not Allowed');
    }

    public function test_abgelaufenes_formular_zeigt_hinweis_und_rueckweg(): void
    {
        $this->app['env'] = 'local';

        $antwort = $this->from(route('site.kontakt'))->post(route('login'), []);

        $antwort->assertStatus(419);
        $this->assertFehlerseite($antwort, 'Die Seite war zu lange geöffnet');
        $antwort->assertSee('laden Sie sie neu');
        $antwort->assertSee('Zurück zur vorherigen Seite');
        $antwort->assertSee(route('site.kontakt'), false);
        $antwort->assertDontSee('Page Expired');
    }

    public function test_fehlende_berechtigung_zeigt_eine_deutsche_seite(): void
    {
        Route::middleware('web')->get('/fehlerseiten-pruefung/verboten', static function (): never {
            abort(403);
        });

        $antwort = $this->get('/fehlerseiten-pruefung/verboten');

        $antwort->assertForbidden();
        $this->assertFehlerseite($antwort, 'Kein Zugriff');
        $antwort->assertSee('nicht berechtigt');
        $antwort->assertDontSee('Forbidden');
    }

    public function test_eine_eigene_begruendung_der_anwendung_wird_auf_der_403_seite_angezeigt(): void
    {
        Route::middleware('web')->get('/fehlerseiten-pruefung/begruendet', static function (): never {
            abort(403, 'Ihrem Konto ist kein Bereich zugeordnet. Bitte wenden Sie sich an den Support.');
        });

        $antwort = $this->get('/fehlerseiten-pruefung/begruendet');

        $antwort->assertForbidden();
        $this->assertFehlerseite($antwort, 'Kein Zugriff');
        $antwort->assertSee('Ihrem Konto ist kein Bereich zugeordnet');
        $antwort->assertDontSee('nicht berechtigt');
    }

    public function test_zu_viele_anfragen_zeigen_eine_deutsche_seite(): void
    {
        Route::middleware(['web', 'throttle:1,1'])->get('/fehlerseiten-pruefung/begrenzt', static fn (): string => 'ok');

        $this->get('/fehlerseiten-pruefung/begrenzt')->assertOk();
        $antwort = $this->get('/fehlerseiten-pruefung/begrenzt');

        $antwort->assertStatus(429);
        $this->assertFehlerseite($antwort, 'Zu viele Anfragen');
        $antwort->assertDontSee('Too Many Requests');
    }

    public function test_serverfehler_zeigt_eine_deutsche_seite_ohne_technische_details(): void
    {
        config()->set('app.debug', false);
        $this->withoutExceptionHandling([])->withExceptionHandling();

        Route::middleware('web')->get('/fehlerseiten-pruefung/kaputt', static function (): never {
            throw new RuntimeException('Interner Fehlertext, der den Nutzer nie erreichen darf.');
        });

        $antwort = $this->get('/fehlerseiten-pruefung/kaputt');

        $antwort->assertStatus(500);
        $this->assertFehlerseite($antwort, 'Es ist ein Fehler aufgetreten');
        $antwort->assertDontSee('Interner Fehlertext');
        $antwort->assertDontSee('Server Error');
    }

    /**
     * @param  TestResponse<Response>  $antwort
     */
    private function assertFehlerseite(TestResponse $antwort, string $titel): void
    {
        $operator = config('smartabrechnen.operator');
        self::assertIsArray($operator);

        $antwort->assertSee('<html lang="de">', false);
        $antwort->assertSee('noindex, nofollow', false);
        $antwort->assertSee($titel);
        $antwort->assertSee('Zur Startseite');
        $antwort->assertSee((string) $operator['legal_name']);
        $antwort->assertSee(route('legal.impressum'), false);
        $antwort->assertSee(route('legal.datenschutz'), false);
        $antwort->assertSee('kontakt@smart-abrechnen.de');
    }
}
