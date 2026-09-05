<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Application\Account\AuditRecorder;
use App\Http\Middleware\SecurityHeaders;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Sicherheitsheader, Hashing, Sitzungscookies und CSRF-Ausnahmen.
 */
final class SecurityConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_oeffentliche_seite_traegt_alle_sicherheitsheader(): void
    {
        $antwort = $this->get(route('site.home'));

        $antwort->assertOk();
        $antwort->assertHeader('X-Content-Type-Options', 'nosniff');
        $antwort->assertHeader('X-Frame-Options', 'DENY');
        $antwort->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $antwort->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $antwort->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');

        $berechtigungen = (string) $antwort->headers->get('Permissions-Policy');
        self::assertStringContainsString('camera=()', $berechtigungen);
        self::assertStringContainsString('microphone=()', $berechtigungen);
        self::assertStringContainsString('geolocation=()', $berechtigungen);
        self::assertStringContainsString('payment=()', $berechtigungen);
    }

    public function test_content_security_policy_deckt_vite_und_alpine_ab(): void
    {
        $antwort = $this->get(route('site.home'));

        $csp = (string) $antwort->headers->get('Content-Security-Policy');

        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertStringContainsString("base-uri 'self'", $csp);
        // form-action muss neben 'self' den Ursprung der gehosteten
        // Zahlungsseite erlauben, weil Chromium und WebKit die Direktive auch
        // gegen das Ziel der Weiterleitung nach dem Bezahl-POST pruefen.
        self::assertStringContainsString(
            "form-action 'self' ".SecurityHeaders::ZAHLUNGSANBIETER_FORM_ACTION.';',
            $csp.';'
        );

        // Der Inline-Baustein zur JavaScript-Erkennung wird ueber seinen Hash
        // freigegeben, nicht ueber unsafe-inline.
        self::assertStringContainsString('sha256-', $csp);
        self::assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);

        // Alpine wertet Attributausdruecke zur Laufzeit aus.
        self::assertStringContainsString("'unsafe-eval'", $csp);
    }

    public function test_unbekannter_pfad_traegt_die_sicherheitsheader(): void
    {
        $antwort = $this->get('/diesen-pfad-gibt-es-nicht');

        $antwort->assertNotFound();
        $antwort->assertHeader('X-Frame-Options', 'DENY');
        $antwort->assertHeader('X-Content-Type-Options', 'nosniff');
        $antwort->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        self::assertStringContainsString("default-src 'self'", (string) $antwort->headers->get('Content-Security-Policy'));
    }

    public function test_nicht_erlaubtes_verfahren_traegt_die_sicherheitsheader(): void
    {
        $antwort = $this->get('/webhooks/stripe');

        $antwort->assertStatus(405);
        $antwort->assertHeader('X-Frame-Options', 'DENY');
        self::assertStringContainsString("default-src 'self'", (string) $antwort->headers->get('Content-Security-Policy'));
    }

    public function test_die_https_umleitung_traegt_die_sicherheitsheader(): void
    {
        $this->app['env'] = 'production';

        $antwort = $this->get('http://beispiel.test/preise');

        $antwort->assertStatus(301);
        $antwort->assertHeader('X-Frame-Options', 'DENY');
        self::assertStringContainsString("default-src 'self'", (string) $antwort->headers->get('Content-Security-Policy'));
    }

    public function test_hsts_wird_ausserhalb_der_produktion_nicht_gesetzt(): void
    {
        $antwort = $this->get(route('site.home'));

        $antwort->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_seiten_der_anwendung_werden_nicht_zwischengespeichert(): void
    {
        $nutzer = $this->nutzerMitOrganisation();

        $antwort = $this->actingAs($nutzer)->get(route('portal.dashboard'));

        $antwort->assertOk();
        self::assertStringContainsString('no-store', (string) $antwort->headers->get('Cache-Control'));
    }

    public function test_die_anwendung_ist_nicht_indexierbar(): void
    {
        $nutzer = $this->nutzerMitOrganisation();

        $antwort = $this->actingAs($nutzer)->get(route('portal.dashboard'));

        $antwort->assertSee('noindex, nofollow', false);
    }

    public function test_argon2id_ist_der_konfigurierte_standard(): void
    {
        self::assertSame('argon2id', config('hashing.driver'));
        self::assertSame(65536, config('hashing.argon.memory'));
        self::assertSame(4, config('hashing.argon.time'));
        self::assertSame(1, config('hashing.argon.threads'));

        $hash = Hash::make('ein-sicheres-passwort-2026');

        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue(Hash::check('ein-sicheres-passwort-2026', $hash));
        self::assertFalse(Hash::check('falsches-passwort', $hash));
    }

    public function test_sitzungscookies_sind_httponly_und_verschluesselt(): void
    {
        self::assertTrue((bool) config('session.http_only'));
        self::assertContains(config('session.same_site'), ['lax', 'strict']);

        // Verschluesselung und Secure-Flag kommen aus der .env. Produktiv sind
        // SESSION_ENCRYPT=true und SESSION_SECURE_COOKIE=true gesetzt.
        self::assertArrayHasKey('encrypt', config('session'));
        self::assertArrayHasKey('secure', config('session'));
    }

    public function test_csrf_schutz_greift_auf_portalformularen(): void
    {
        $nutzer = $this->nutzerMitOrganisation();
        $this->app['env'] = 'local';

        $antwort = $this->actingAs($nutzer)->post(route('portal.objekte.store'), [
            'label' => 'Testobjekt',
            'address_line' => 'Musterweg 1',
            'postal_code' => '40789',
            'city' => 'Monheim am Rhein',
            'kind' => 'MEHRFAMILIENHAUS',
        ]);

        $antwort->assertStatus(419);
    }

    public function test_webhookrouten_sind_von_der_csrf_pruefung_ausgenommen(): void
    {
        Route::middleware('web')->post('webhooks/pruefung', static fn (): string => 'ok');

        $this->app['env'] = 'local';

        $antwort = $this->post('webhooks/pruefung');

        $antwort->assertOk();
        $antwort->assertSee('ok');
    }

    public function test_portalformular_enthaelt_ein_csrf_token(): void
    {
        $nutzer = $this->nutzerMitOrganisation();

        $antwort = $this->actingAs($nutzer)->get(route('portal.objekte.create'));

        $antwort->assertOk();
        $antwort->assertSee('name="_token"', false);
    }

    public function test_ip_adressen_werden_im_revisionsprotokoll_gekuerzt(): void
    {
        self::assertSame('192.168.178.0', AuditRecorder::truncate('192.168.178.42'));
        self::assertSame('2001:db8:85a3::', AuditRecorder::truncate('2001:db8:85a3:8d3:1319:8a2e:370:7348'));
        self::assertNull(AuditRecorder::truncate('kein-wert'));
    }

    private function nutzerMitOrganisation(): User
    {
        /** @var User $nutzer */
        $nutzer = User::factory()->create();
        $organisation = Organization::factory()->create();

        OrganizationUser::query()->create([
            'organization_id' => $organisation->getKey(),
            'user_id' => $nutzer->getKey(),
            'role' => 'OWNER',
            'joined_at' => now(),
        ]);

        return $nutzer;
    }
}
