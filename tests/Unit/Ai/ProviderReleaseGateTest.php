<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\AiProviderKey;
use App\Services\Ai\Exceptions\ProviderNotReleasedException;
use App\Services\Ai\ProviderReleaseGate;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Ai\Support\AiTestFactory;

/**
 * Harte Freigabesperre vor jedem echten Provideraufruf (Abschnitt 13.5).
 *
 * Verbindlich: Solange AI_REQUIRE_ZERO_DATA_RETENTION aktiv und
 * AI_DATA_RETENTION_APPROVED false ist, wird jeder externe Provider
 * blockiert. Das Setzen von store=false oder ein Loeschaufruf allein gilt
 * NICHT als Zero Data Retention.
 */
final class ProviderReleaseGateTest extends TestCase
{
    public function test_externer_provider_ist_ohne_freigabe_produktiv_gesperrt(): void
    {
        $gate = new ProviderReleaseGate(true, false, 'production');

        self::assertFalse($gate->isReleased(AiProviderKey::OPENAI));
        self::assertFalse($gate->isReleased(AiProviderKey::ANTHROPIC));
    }

    public function test_gesperrter_provider_wirft_ausnahme_mit_begruendung(): void
    {
        $gate = new ProviderReleaseGate(true, false, 'production');

        try {
            $gate->assertReleased(AiProviderKey::OPENAI);
            self::fail('Es wurde keine Ausnahme geworfen.');
        } catch (ProviderNotReleasedException $exception) {
            self::assertStringContainsString('openai', $exception->getMessage());
            self::assertStringContainsString('AI_DATA_RETENTION_APPROVED', $exception->getMessage());
            self::assertStringContainsString('store=false', $exception->getMessage());
        }
    }

    public function test_freigegebener_provider_wird_durchgelassen(): void
    {
        $gate = new ProviderReleaseGate(true, true, 'production');

        self::assertTrue($gate->isReleased(AiProviderKey::OPENAI));
        self::assertNull($gate->blockReason(AiProviderKey::ANTHROPIC));

        $gate->assertReleased(AiProviderKey::OPENAI);
        $this->addToAssertionCount(1);
    }

    public function test_ohne_zdr_pflicht_ist_der_provider_frei(): void
    {
        $gate = new ProviderReleaseGate(false, false, 'production');

        self::assertTrue($gate->isReleased(AiProviderKey::OPENAI));
    }

    public function test_externer_provider_ist_auch_lokal_ohne_freigabe_gesperrt(): void
    {
        $gate = new ProviderReleaseGate(true, false, 'local');

        self::assertFalse(
            $gate->isReleased(AiProviderKey::ANTHROPIC),
            'Auch in der lokalen Umgebung wuerden echte Dokumentinhalte uebertragen.',
        );
    }

    public function test_testprovider_laeuft_in_der_testumgebung_ohne_freigabe(): void
    {
        $gate = new ProviderReleaseGate(true, false, 'testing');

        self::assertTrue($gate->isReleased(AiProviderKey::FAKE));
        self::assertNull($gate->blockReason(AiProviderKey::FAKE));
    }

    public function test_testprovider_laeuft_lokal_ohne_freigabe(): void
    {
        $gate = new ProviderReleaseGate(true, false, 'local');

        self::assertTrue($gate->isReleased(AiProviderKey::FAKE));
    }

    public function test_testprovider_ist_produktiv_gesperrt(): void
    {
        $gate = new ProviderReleaseGate(true, true, 'production');

        self::assertFalse($gate->isReleased(AiProviderKey::FAKE));
        self::assertStringContainsString('local und testing', (string) $gate->blockReason(AiProviderKey::FAKE));
    }

    public function test_gate_kann_aus_der_konfiguration_gebaut_werden(): void
    {
        $gate = ProviderReleaseGate::fromConfig(
            AiTestFactory::config(['data_retention_approved' => false]),
            'production',
        );

        self::assertFalse($gate->isReleased(AiProviderKey::OPENAI));
        self::assertFalse($gate->isNonProductionEnvironment());
    }
}
