<?php

declare(strict_types=1);

namespace App\Services\Ai\Integration;

use App\Application\Documents\Contracts\ProviderFileDeleter;
use App\Application\Documents\Dto\ProviderFileDeletionReport;
use App\Enums\AiProvider;
use App\Services\Ai\AiConfig;
use App\Services\Ai\AiProviderKey;
use App\Services\Ai\Http\AiHttpClientInterface;
use App\Services\Ai\Http\AiHttpRequest;
use App\Services\Ai\Http\AiHttpResponse;
use App\Services\Ai\ProviderConfig;
use App\Services\Ai\RedactingLogger;
use Throwable;

/**
 * Entfernt eine temporaer beim Provider angelegte Datei ueber dessen
 * Loeschschnittstelle (Abschnitt 6.3 Schritt 14).
 *
 * WARUM EIGENSTAENDIG UND NICHT UEBER DEN PROVIDER: Der Loeschpfad wird auch
 * dann aufgerufen, wenn kein Extraktionslauf mehr laeuft, zum Beispiel durch
 * den TTL-Cleanup oder durch RetryFailedDeletions. Er darf deshalb nicht von
 * Schema, Prompt, Routing oder Freigabesperre abhaengen. Diese Klasse kennt
 * nur Provider, Endpunkt und Datei-ID.
 *
 * DIE FREIGABESPERRE GILT HIER AUSDRUECKLICH NICHT. Sie verhindert die
 * Uebertragung von Dokumentinhalten. Eine Loeschung uebertraegt nichts,
 * sondern entfernt bereits uebertragene Daten. Eine Sperre der Loeschung waere
 * das Gegenteil des Schutzzwecks.
 *
 * KEIN STILLER ERFOLG: Jeder Fehlschlag, auch eine unerwartete Ausnahme oder
 * eine fehlende Providerkonfiguration, wird als FEHLGESCHLAGEN gemeldet.
 * DeleteOriginalSources uebernimmt den Status, der Adminbereich zeigt ihn als
 * Datenschutzalarm und RetryFailedDeletions wiederholt den Vorgang. Es wird
 * niemals eine Ausnahme geworfen, damit der lokale Loeschpfad in jedem Fall
 * weiterlaeuft.
 *
 * DATENSCHUTZ: Die Provider-Datei-ID wird weder protokolliert noch in den
 * Bericht uebernommen. Fuer das Protokoll dient ausschliesslich ein gekuerzter
 * Hash.
 */
final class AiProviderFileDeleter implements ProviderFileDeleter
{
    /**
     * Pfad der Files-API. Beide unterstuetzten Provider verwenden denselben
     * Pfad relativ zur jeweiligen Basis-URI.
     */
    public const FILES_PATH = 'files';

    public const ERROR_NOT_CONFIGURED = 'provider_nicht_konfiguriert';

    public const ERROR_CALL_FAILED = 'loeschaufruf_fehlgeschlagen';

    public const ERROR_NOT_CONFIRMED = 'loeschung_nicht_bestaetigt';

    public function __construct(
        private readonly AiConfig $config,
        private readonly AiHttpClientInterface $httpClient,
        private readonly RedactingLogger $logger,
    ) {}

    public function deleteProviderFile(AiProvider $provider, string $providerFileId): ProviderFileDeletionReport
    {
        $providerFileId = trim($providerFileId);

        if ($providerFileId === '') {
            return ProviderFileDeletionReport::notRequired();
        }

        $providerConfig = $this->config->provider($this->providerKey($provider));

        if ($providerConfig === null || ! $providerConfig->hasApiKey()) {
            // Ohne Zugangsdaten kann nicht geloescht werden. Das ist ein
            // offener Datenschutzvorgang, kein stiller Erfolg.
            return $this->reportFailure($provider, $providerFileId, self::ERROR_NOT_CONFIGURED);
        }

        try {
            $response = $this->httpClient->send(AiHttpRequest::delete(
                $providerConfig->endpoint(self::FILES_PATH.'/'.rawurlencode($providerFileId)),
                $this->headers($providerConfig),
                $providerConfig->timeoutSeconds,
            ));
        } catch (Throwable) {
            // Die Meldung wird bewusst verworfen: eine Transportausnahme kann
            // URL, Datei-ID oder Providerantwort tragen.
            return $this->reportFailure($provider, $providerFileId, self::ERROR_CALL_FAILED);
        }

        if (! $response->isSuccessful()) {
            // 404 gilt nicht als Erfolg. Ob die Datei nie existierte oder ein
            // Rechteproblem vorliegt, ist von hier aus nicht unterscheidbar;
            // ein Wiederholungsversuch ist die sichere Annahme.
            return $this->reportFailure(
                $provider,
                $providerFileId,
                $response->errorCode() ?? ('http_'.$response->statusCode),
            );
        }

        if (! $this->confirmsDeletion($response)) {
            return $this->reportFailure($provider, $providerFileId, self::ERROR_NOT_CONFIRMED);
        }

        return ProviderFileDeletionReport::deleted();
    }

    /**
     * OpenAI bestaetigt die Loeschung ausdruecklich mit deleted true. Fehlt das
     * Feld ganz, wie bei Anthropic, gilt ein erfolgreicher Statuscode als
     * Bestaetigung. Ein ausdrueckliches deleted false wird nicht als Erfolg
     * gewertet.
     */
    private function confirmsDeletion(AiHttpResponse $response): bool
    {
        $decoded = $response->decoded();

        if ($decoded === null || ! array_key_exists('deleted', $decoded)) {
            return true;
        }

        return $decoded['deleted'] === true;
    }

    /**
     * @return array<string, string>
     */
    private function headers(ProviderConfig $providerConfig): array
    {
        if ($providerConfig->key === AiProviderKey::ANTHROPIC) {
            return [
                'x-api-key' => (string) $providerConfig->apiKey(),
                'anthropic-version' => $providerConfig->apiVersion ?? '2023-06-01',
                'accept' => 'application/json',
            ];
        }

        return [
            'authorization' => 'Bearer '.((string) $providerConfig->apiKey()),
            'accept' => 'application/json',
        ];
    }

    private function providerKey(AiProvider $provider): AiProviderKey
    {
        return match ($provider) {
            AiProvider::OPENAI => AiProviderKey::OPENAI,
            AiProvider::ANTHROPIC => AiProviderKey::ANTHROPIC,
        };
    }

    private function reportFailure(AiProvider $provider, string $providerFileId, string $errorCode): ProviderFileDeletionReport
    {
        $this->logger->error('Loeschung einer temporaeren Providerdatei fehlgeschlagen, Datenschutzalarm', [
            'provider' => $provider->value,
            'provider_file_handle_hash' => substr(hash('sha256', $providerFileId), 0, 16),
            'error_code' => mb_substr($errorCode, 0, 120),
        ]);

        return ProviderFileDeletionReport::failed($errorCode);
    }
}
