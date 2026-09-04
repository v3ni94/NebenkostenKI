<?php

declare(strict_types=1);

namespace App\Services\Ai\Integration;

use App\Application\Documents\Support\ProviderFileDeleterResolver;
use App\Enums\AiProvider;
use App\Enums\DeletionStatus;
use App\Models\Document;
use App\Models\TemporaryUpload;
use App\Services\Ai\RedactingLogger;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Raeumt eine offene Providerdatei aus einem frueheren Aufruf weg, bevor ein
 * neuer Provideraufruf beginnt (Abschnitt 6.4, ADR-007).
 *
 * WARUM: Der Kurzzeitdatensatz fuehrt genau eine Provider-Datei-ID. Ist deren
 * Loeschung nach der Klassifikation fehlgeschlagen, darf die Extraktion keine
 * zweite Datei anlegen. Die Datei-ID ist bekannt, deshalb wird die Loeschung
 * hier sofort erneut versucht. Gelingt sie, laeuft die Auswertung normal
 * weiter. Gelingt sie nicht, bleibt die ID stehen und der Aufruf wartet auf
 * den naechsten Versuch. Ein gueltiges Dokument wird dadurch niemals mit einer
 * Dateiformatmeldung endgueltig abgelehnt.
 *
 * KEIN STILLER ERFOLG: Nur eine vom Provider bestaetigte Loeschung gibt die
 * ID frei. Eine Meldung "nicht erforderlich" ohne Providerbindung zaehlt
 * nicht, weil dann niemand die Datei geloescht hat.
 */
final class OpenProviderFileGuard
{
    public function __construct(
        private readonly ProviderFileDeleterResolver $deleters,
        private readonly RedactingLogger $logger,
    ) {}

    /**
     * @return bool true, wenn keine offene Providerdatei mehr vorliegt
     */
    public function release(Document $document, TemporaryUpload $upload): bool
    {
        if (! DocumentAiCallObserver::hasUnresolvedProviderFile($upload)) {
            return true;
        }

        $provider = $upload->getAttribute('provider');
        $fileId = (string) $upload->getAttribute('provider_file_id');

        if (! $provider instanceof AiProvider) {
            $this->logger->error('Offene Providerdatei ohne Providerangabe, Loeschung nicht moeglich', [
                'correlation_id' => (string) $document->getKey(),
            ]);

            return false;
        }

        try {
            $report = $this->deleters->resolve()->deleteProviderFile($provider, $fileId);
            $status = $report->status;
            $errorCode = $report->errorCode;
        } catch (Throwable) {
            // Die Meldung wird verworfen, sie koennte URL oder Datei-ID tragen.
            $status = DeletionStatus::FEHLGESCHLAGEN;
            $errorCode = AiProviderFileDeleter::ERROR_CALL_FAILED;
        }

        if ($status === DeletionStatus::ERFOLGREICH) {
            $upload->forceFill([
                'provider_file_id' => null,
                'provider_file_deleted_at' => Carbon::now(),
                'provider_deletion_status' => DeletionStatus::ERFOLGREICH,
            ])->save();

            $this->logger->info('Offene Providerdatei vor dem naechsten Aufruf geloescht', [
                'provider' => $provider->value,
                'correlation_id' => (string) $document->getKey(),
            ]);

            return true;
        }

        $upload->forceFill([
            'provider_deletion_status' => DeletionStatus::FEHLGESCHLAGEN,
        ])->save();

        $this->logger->error('Offene Providerdatei weiterhin nicht geloescht, Aufruf wird zurueckgestellt', [
            'provider' => $provider->value,
            'provider_file_handle_hash' => substr(hash('sha256', $fileId), 0, 16),
            'deletion_status' => $status->value,
            'error_code' => $errorCode,
            'correlation_id' => (string) $document->getKey(),
        ]);

        return false;
    }
}
