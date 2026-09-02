<?php

declare(strict_types=1);

namespace Tests\Feature\Install;

use App\Application\Install\TrustedProxyConfiguration;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Hinter dem IONOS-Proxy muessen Schema und Client-IP aus den
 * X-Forwarded-Headern gelesen werden, aber nur, wenn dem Proxy vertraut wird.
 */
final class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->zuruecksetzen();
    }

    protected function tearDown(): void
    {
        $this->zuruecksetzen();

        parent::tearDown();
    }

    /**
     * Die Middleware haelt ihre Konfiguration statisch, Symfony die Liste der
     * vertrauten Adressen ebenfalls. Im Betrieb ist das je Prozess genau eine
     * Anfrage; im Testlauf muss der Zustand ausdruecklich geleert werden.
     */
    private function zuruecksetzen(): void
    {
        TrustedProxyConfiguration::apply(config('deploy.trusted_proxies'));
        Request::setTrustedProxies([], TrustedProxyConfiguration::HEADERS);
    }

    public function test_mit_vertrautem_proxy_werden_https_und_client_ip_erkannt(): void
    {
        TrustedProxyConfiguration::apply('*');

        $this->get('http://localhost/', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '203.0.113.7',
            'X-Forwarded-Port' => '443',
        ])->assertOk();

        /** @var Request $anfrage */
        $anfrage = $this->app->make('request');

        $this->assertTrue($anfrage->isSecure(), 'HTTPS muss aus X-Forwarded-Proto erkannt werden.');
        $this->assertSame('203.0.113.7', $anfrage->ip(), 'Die Client-IP muss aus X-Forwarded-For stammen.');
    }

    public function test_ohne_vertrauen_bleiben_die_header_wirkungslos(): void
    {
        TrustedProxyConfiguration::apply('');

        $this->get('http://localhost/', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '203.0.113.7',
        ])->assertOk();

        /** @var Request $anfrage */
        $anfrage = $this->app->make('request');

        $this->assertFalse($anfrage->isSecure());
        $this->assertSame('127.0.0.1', $anfrage->ip());
    }

    public function test_nur_die_angegebene_proxyadresse_wird_vertraut(): void
    {
        TrustedProxyConfiguration::apply('10.9.8.7');

        $this->get('http://localhost/', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.7'])->assertOk();

        /** @var Request $anfrage */
        $anfrage = $this->app->make('request');

        // Die Testanfrage kommt von 127.0.0.1, nicht vom eingetragenen Proxy.
        $this->assertFalse($anfrage->isSecure());
        $this->assertSame('127.0.0.1', $anfrage->ip());
    }

    public function test_standard_aus_der_konfiguration_ist_leer(): void
    {
        $this->assertSame('', config('deploy.trusted_proxies'));
    }
}
