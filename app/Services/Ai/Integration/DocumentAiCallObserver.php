<?php

declare(strict_types=1);

namespace App\Services\Ai\Integration;

use App\Application\Documents\Support\ActiveJobHeartbeat;
use App\Enums\AiCallStatus;
use App\Enums\DeletionStatus;
use App\Models\Document;
use App\Models\TemporaryUpload;
use App\Services\Ai\AiCallObserver;
use App\Services\Ai\AiProviderKey;
use App\Services\Ai\Dto\AiCallMetadata;
use App\Services\Ai\Dto\ProviderFileDeletionOutcome;
use App\Services\Ai\RedactingLogger;
use Illuminate\Support\Carbon;

/**
 * Verbindet einen KI-Aufruf mit dem Kurzzeitdatensatz des Dokuments
 * (Abschnitt 6.3 Schritte 9, 13 und 14, ADR-007).
 *
 * PROVIDERDATEI: Sobald der Provider eine temporaere Datei angelegt hat, wird
 * ihre ID verschluesselt in temporary_uploads.provider_file_id festgehalten
 * und provider_deletion_status auf OFFEN gesetzt. Nach bestaetigter Loeschung
 * wird die ID entfernt (Abschnitt 6.4: keine dauerhafte Speicherung). Bleibt
 * die Loeschung aus, bleibt die ID stehen und der Status wird FEHLGESCHLAGEN:
 * DeleteOriginalSources wiederholt den Loeschaufruf sofort, RetryFailedDeletions
 * und der Datenschutzmonitor sehen den offenen Vorgang. Bricht der Prozess
 * zwischen Upload und Loeschung ab, bleibt die ID mit Status OFFEN stehen und
 * ist damit ebenfalls nachverfolgbar.
 *
 * HEARTBEAT: Vor jedem Providerrequest wird das Lease des laufenden Teiljobs
 * verlaengert, damit ein langer Aufruf nicht von einem zweiten Lauf
 * uebernommen wird.
 *
 * ABGEBROCHENE AUFRUFE: Endet ein Aufruf nach gesendeten Requests mit einer
 * Ausnahme, wird der bis dahin entstandene Verbrauch als eigener Datensatz in
 * ai_calls nachgewiesen, damit Tagesbudget und Kostenuebersicht vollstaendig
 * bleiben.
 */
final class DocumentAiCallObserver implements AiCallObserver
{
    public function __construct(
        private readonly Document $document,
        private readonly TemporaryUpload $upload,
        private readonly AiCallRecorder $calls,
        private readonly RedactingLogger $logger,
    ) {}

    public function beforeProviderRequest(string $providerKey): void
    {
        if (! ActiveJobHeartbeat::beat()) {
            $this->logger->warning('Lease des Teiljobs waehrend eines KI-Aufrufs verloren', [
                'provider' => $providerKey,
                'correlation_id' => (string) $this->document->getKey(),
            ]);
        }
    }

    public function providerFileCreated(string $providerKey, string $providerFileId): void
    {
        $provider = AiProviderKey::tryFromKey($providerKey)?->toAiProviderEnum();

        if ($provider === null) {
            return;
        }

        $this->upload->forceFill([
            'provider' => $provider,
            'provider_file_id' => $providerFileId,
            'provider_file_deleted_at' => null,
            'provider_deletion_status' => DeletionStatus::OFFEN,
        ])->save();
    }

    public function providerFileReleased(string $providerKey, string $providerFileId, ProviderFileDeletionOutcome $outcome): void
    {
        if ($outcome->status === DeletionStatus::ERFOLGREICH) {
            $this->upload->forceFill([
                'provider_file_id' => null,
                'provider_file_deleted_at' => Carbon::now(),
                'provider_deletion_status' => DeletionStatus::ERFOLGREICH,
            ])->save();

            return;
        }

        // Die ID bleibt stehen, damit die Loeschung wiederholt werden kann.
        $this->upload->forceFill([
            'provider_deletion_status' => DeletionStatus::FEHLGESCHLAGEN,
        ])->save();

        $this->logger->error('Providerdatei nicht geloescht, Vorgang zur Wiederholung vorgemerkt', [
            'provider' => $providerKey,
            'provider_file_handle_hash' => $outcome->providerFileHandleHash,
            'error_code' => $outcome->errorCode,
            'correlation_id' => (string) $this->document->getKey(),
        ]);
    }

    public function providerCallAborted(AiCallMetadata $metadata): void
    {
        $this->calls->record(
            $this->document,
            $metadata,
            1,
            $metadata->status === AiCallStatus::RATE_LIMIT
                ? AiIntegrationErrorCode::PROVIDER_RATE_LIMIT
                : AiIntegrationErrorCode::PROVIDER_NICHT_ERREICHBAR,
        );
    }

    /**
     * Sicherheitsnetz fuer den Fall, dass ein Loeschstatus im Ergebnis
     * FEHLGESCHLAGEN meldet, der Datensatz das aber noch nicht traegt.
     *
     * @param  list<ProviderFileDeletionOutcome>  $outcomes
     */
    public function noteDeletionOutcomes(array $outcomes): void
    {
        foreach ($outcomes as $outcome) {
            if (! $outcome->isPrivacyAlert()) {
                continue;
            }

            if ($this->upload->getAttribute('provider_deletion_status') !== DeletionStatus::FEHLGESCHLAGEN) {
                $this->upload->forceFill(['provider_deletion_status' => DeletionStatus::FEHLGESCHLAGEN])->save();
            }
        }
    }

    /**
     * Traegt der Kurzzeitdatensatz noch eine Providerdatei, deren Loeschung
     * offen oder fehlgeschlagen ist, darf keine weitere angelegt werden. Die
     * Spalte fuehrt genau eine ID; eine zweite wuerde die erste ueberschreiben
     * und damit unauffindbar machen.
     */
    public static function hasUnresolvedProviderFile(TemporaryUpload $upload): bool
    {
        $fileId = $upload->getAttribute('provider_file_id');

        return is_string($fileId) && $fileId !== '';
    }
}
