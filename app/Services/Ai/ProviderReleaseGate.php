<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Exceptions\ProviderNotReleasedException;

/**
 * HARTE FREIGABESPERRE VOR JEDEM ECHTEN PROVIDERAUFRUF.
 *
 * Regel (Abschnitt 13.5, ADR-008): Solange
 * ai.require_zero_data_retention true und ai.data_retention_approved false
 * ist, wird jeder externe Provider blockiert. Ein Fallback darf diese Sperre
 * NICHT umgehen. Deshalb prueft der AiProviderRouter die Sperre fuer den
 * Primaerprovider und fuer den Fallbackprovider getrennt.
 *
 * WAS NICHT ALS ZERO DATA RETENTION GILT:
 *
 * - Das Setzen von store=false in einer OpenAI-Responses-Anfrage. Es reduziert
 *   die API-seitige Persistenz, ist aber keine ZDR-Freigabe des Projekts.
 * - Ein API-Loeschaufruf auf eine Providerdatei. Das Loeschen eines
 *   Files-API-Objekts garantiert keine sofortige physische Loeschung aller
 *   Providerkopien.
 * - Eine kurze expires_after-Frist.
 *
 * Zero Data Retention beziehungsweise eine gleichwertig freigegebene
 * Kurzzeitverarbeitung ist eine organisatorische Zusage des Providers fuer die
 * konkrete Providerorganisation, die genutzten Modelle und die genutzten
 * Funktionen. Sie ist mit AVV beziehungsweise DPA und aktueller
 * Retention-Dokumentation nachzuweisen. Erst dann darf
 * AI_DATA_RETENTION_APPROVED auf true gesetzt werden. Im UI darf store=false
 * oder ein Loeschaufruf nicht als Zero Data Retention bezeichnet werden.
 *
 * SONDERREGEL TESTPROVIDER: Der FakeAiProvider fuehrt keinen Netzwerkaufruf
 * aus und uebertraegt keine Daten. Er darf in den Umgebungen local und
 * testing ohne Freigabe laufen. Produktiv ist er gesperrt, weil er keine
 * fachlich belastbaren Ergebnisse liefert.
 */
final class ProviderReleaseGate
{
    /**
     * Umgebungen, in denen der Testprovider ohne Freigabe laufen darf.
     */
    public const NON_PRODUCTION_ENVIRONMENTS = ['local', 'testing'];

    /**
     * @param  list<string>|null  $approvedProviderKeys  Ausdrueckliche Freigabe je Providerorganisation.
     *                                                   null bedeutet, dass ausschliesslich die globale
     *                                                   Freigabe aus ai.data_retention_approved gilt.
     */
    public function __construct(
        private readonly bool $requireZeroDataRetention,
        private readonly bool $dataRetentionApproved,
        private readonly string $environment,
        private readonly ?array $approvedProviderKeys = null,
    ) {}

    /**
     * @param  list<string>|null  $approvedProviderKeys
     */
    public static function fromConfig(AiConfig $config, string $environment, ?array $approvedProviderKeys = null): self
    {
        return new self(
            $config->requireZeroDataRetention,
            $config->dataRetentionApproved,
            $environment,
            $approvedProviderKeys,
        );
    }

    public function isNonProductionEnvironment(): bool
    {
        return in_array(strtolower($this->environment), self::NON_PRODUCTION_ENVIRONMENTS, true);
    }

    public function isReleased(AiProviderKey $provider): bool
    {
        return $this->blockReason($provider) === null;
    }

    /**
     * Grund der Sperre in deutscher Sprache oder null, wenn freigegeben.
     */
    public function blockReason(AiProviderKey $provider): ?string
    {
        if ($provider === AiProviderKey::FAKE) {
            if ($this->isNonProductionEnvironment()) {
                return null;
            }

            return 'Der Testprovider ist ausschliesslich in den Umgebungen local und testing zugelassen.';
        }

        if (! $this->requireZeroDataRetention) {
            return null;
        }

        // Die Freigabe gilt nach Abschnitt 13.5 fuer die konkrete
        // Providerorganisation. Ist eine Liste freigegebener Organisationen
        // hinterlegt, entscheidet ausschliesslich sie.
        if ($this->approvedProviderKeys !== null) {
            if (in_array($provider->value, $this->approvedProviderKeys, true)) {
                return null;
            }

            return sprintf(
                'Fuer die Providerorganisation "%s" liegt keine Datenschutzfreigabe vor. Zero Data Retention '
                .'beziehungsweise eine gleichwertig freigegebene Kurzzeitverarbeitung ist je Organisation, '
                .'Modell und genutzter Funktion nachzuweisen.',
                $provider->value,
            );
        }

        if ($this->dataRetentionApproved) {
            return null;
        }

        return 'AI_REQUIRE_ZERO_DATA_RETENTION ist aktiv und AI_DATA_RETENTION_APPROVED steht auf false. '
            .'Zero Data Retention beziehungsweise eine gleichwertig freigegebene Kurzzeitverarbeitung ist fuer die '
            .'konkrete Providerorganisation, die genutzten Modelle und die genutzten Funktionen nachzuweisen. '
            .'Das Setzen von store=false oder ein Loeschaufruf allein genuegt dafuer nicht.';
    }

    /**
     * @throws ProviderNotReleasedException
     */
    public function assertReleased(AiProviderKey $provider): void
    {
        $reason = $this->blockReason($provider);

        if ($reason === null) {
            return;
        }

        throw ProviderNotReleasedException::forProvider($provider->value, $reason);
    }
}
