<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use App\Application\Install\TrustedProxyConfiguration;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class TrustedProxyConfigurationTest extends TestCase
{
    public function test_leer_bedeutet_keinem_proxy_vertrauen(): void
    {
        $this->assertNull(TrustedProxyConfiguration::parse(''));
        $this->assertNull(TrustedProxyConfiguration::parse('   '));
        $this->assertNull(TrustedProxyConfiguration::parse(null));
        $this->assertNull(TrustedProxyConfiguration::parse(', ,'));
        $this->assertFalse(TrustedProxyConfiguration::isConfigured(''));
    }

    public function test_stern_vertraut_allen_proxys(): void
    {
        $this->assertSame('*', TrustedProxyConfiguration::parse('*'));
        $this->assertSame('*', TrustedProxyConfiguration::parse(' ** '));
        $this->assertTrue(TrustedProxyConfiguration::isConfigured('*'));
    }

    public function test_adressen_werden_kommagetrennt_gelesen(): void
    {
        $this->assertSame(
            ['10.0.0.1', '192.168.0.0/16'],
            TrustedProxyConfiguration::parse('10.0.0.1, 192.168.0.0/16,'),
        );
    }

    public function test_es_werden_die_vier_x_forwarded_header_verwendet(): void
    {
        $headers = TrustedProxyConfiguration::HEADERS;

        $this->assertSame(Request::HEADER_X_FORWARDED_FOR, $headers & Request::HEADER_X_FORWARDED_FOR);
        $this->assertSame(Request::HEADER_X_FORWARDED_HOST, $headers & Request::HEADER_X_FORWARDED_HOST);
        $this->assertSame(Request::HEADER_X_FORWARDED_PORT, $headers & Request::HEADER_X_FORWARDED_PORT);
        $this->assertSame(Request::HEADER_X_FORWARDED_PROTO, $headers & Request::HEADER_X_FORWARDED_PROTO);
        $this->assertSame(0, $headers & Request::HEADER_FORWARDED, 'Der RFC-7239-Header wird nicht ausgewertet.');
    }
}
