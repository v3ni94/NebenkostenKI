<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Enums\AiProvider;
use App\Enums\DeletionStatus;
use App\Models\Document;
use App\Models\SourceDeletionEvent;
use App\Models\TemporaryUpload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Datenschutzmonitor des Adminbereichs (Masterprompt 19, 20).
 *
 * VERBINDLICHE DATENSPARSAMKEIT
 *
 *  - Es werden ausschliesslich Anzahl, Alter, Status, Versuchszaehler und
 *    Fehlercode gemeldet.
 *  - Es wird NIEMALS ein Dateiinhalt, ein Originaldateiname, ein temporaerer
 *    Storage-Key oder eine Provider-Datei-ID gemeldet. Die Tabellen fuehren
 *    ohnehin keinen Originaldateinamen, siehe Migration der Dokumenttabellen.
 *  - Referenziert wird nur die technische ULID des Dokuments. Sie ist ohne
 *    Mandantenzugriff inhaltsleer.
 *
 * FEHLGESCHLAGENE UND UEBERFAELLIGE LOESCHUNGEN sind ein kritischer Alarm und
 * werden getrennt gezaehlt, damit sie nicht in einer Gesamtzahl untergehen.
 */
final class PrivacyMonitor
{
    /**
     * Loeschstatus, die noch offen sind.
     *
     * @var list<string>
     */
    private const array OFFEN = [
        'OFFEN',
        'IN_ARBEIT',
    ];

    /**
     * Loeschstatus, die einen Alarm ausloesen.
     *
     * @var list<string>
     */
    private const array ALARM = [
        'FEHLGESCHLAGEN',
        'UEBERFAELLIG',
    ];

    /**
     * Gesamtsicht fuer die Uebersicht und den Datenschutzbereich.
     *
     * @return array{
     *     ueberfaellige_uploads: int,
     *     aeltester_upload_minuten: int|null,
     *     offene_lokale_loeschungen: int,
     *     offene_providerloeschungen: int,
     *     fehlgeschlagene_loeschungen: int,
     *     alarm: bool
     * }
     */
    public function summary(): array
    {
        $overdue = $this->overdueTemporaryUploads();
        $failed = $this->failedDeletionCount();

        return [
            'ueberfaellige_uploads' => $overdue,
            'aeltester_upload_minuten' => $this->oldestOverdueUploadMinutes(),
            'offene_lokale_loeschungen' => $this->openLocalDeletionCount(),
            'offene_providerloeschungen' => $this->openProviderDeletionCount(),
            'fehlgeschlagene_loeschungen' => $failed,
            'alarm' => $failed > 0 || $overdue > 0,
        ];
    }

    /**
     * Temporaere Uploads, deren Kurzzeit-TTL abgelaufen ist und die noch einen
     * Inhalt tragen.
     */
    public function overdueTemporaryUploads(): int
    {
        return $this->overdueQuery()->count();
    }

    public function oldestOverdueUploadMinutes(): ?int
    {
        $value = $this->overdueQuery()->min('expires_at');

        if (! is_string($value) && ! $value instanceof Carbon) {
            return null;
        }

        return (int) Carbon::parse((string) $value)->diffInMinutes(Carbon::now(), true);
    }

    /**
     * Offene lokale Loeschungen: das Dokument ist verarbeitet, der Nachweis der
     * Loeschung fehlt aber noch.
     */
    public function openLocalDeletionCount(): int
    {
        return Document::query()
            ->whereIn('deletion_status', self::OFFEN)
            ->whereNull('original_deleted_at')
            ->count();
    }

    /**
     * Offene Providerloeschungen: eine Datei liegt noch beim KI-Provider.
     */
    public function openProviderDeletionCount(): int
    {
        return TemporaryUpload::query()
            ->whereNotNull('provider_file_id')
            ->whereIn('provider_deletion_status', self::OFFEN)
            ->count();
    }

    public function failedDeletionCount(): int
    {
        return Document::query()
            ->whereIn('deletion_status', self::ALARM)
            ->count();
    }

    /**
     * Fehlgeschlagene Loeschungen als Alarmliste.
     *
     * Bewusst ohne jede inhaltliche Angabe: nur Dokumentkennung, Status,
     * Versuchszaehler, Fehlercode und Alter.
     *
     * @return list<array{
     *     dokument_id: string,
     *     status: string,
     *     lokal: string,
     *     provider: string,
     *     versuch: int,
     *     fehlercode: string|null,
     *     alter_stunden: int|null
     * }>
     */
    public function failedDeletions(int $limit = 50): array
    {
        /** @var list<Document> $documents */
        $documents = Document::query()
            ->whereIn('deletion_status', self::ALARM)
            ->orderBy('updated_at')
            ->limit($limit)
            ->get()
            ->all();

        $rows = [];

        foreach ($documents as $document) {
            $id = (string) $document->getKey();
            $event = $this->latestEventFor($id);
            $status = $document->getAttribute('deletion_status');

            $rows[] = [
                'dokument_id' => $id,
                'status' => $status instanceof DeletionStatus ? $status->label() : (string) $status,
                'lokal' => $this->statusLabel($event?->getAttribute('local_deletion_status')),
                'provider' => $this->statusLabel($event?->getAttribute('provider_deletion_status')),
                'versuch' => (int) ($event?->getAttribute('attempt') ?? 0),
                'fehlercode' => $this->errorCode($event),
                'alter_stunden' => $this->ageInHours($document->getAttribute('updated_at')),
            ];
        }

        return $rows;
    }

    /**
     * Offene Providerloeschungen als Liste, ohne Provider-Datei-ID.
     *
     * @return list<array{
     *     dokument_id: string,
     *     provider: string,
     *     status: string,
     *     alter_minuten: int|null
     * }>
     */
    public function openProviderDeletions(int $limit = 50): array
    {
        /** @var list<TemporaryUpload> $uploads */
        $uploads = TemporaryUpload::query()
            ->whereNotNull('provider_file_id')
            ->whereIn('provider_deletion_status', self::OFFEN)
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->all();

        $rows = [];

        foreach ($uploads as $upload) {
            $documentId = $upload->getAttribute('document_id');
            $provider = $upload->getAttribute('provider');

            $rows[] = [
                'dokument_id' => is_string($documentId) ? $documentId : '',
                'provider' => $provider instanceof AiProvider ? $provider->value : (string) $provider,
                'status' => $this->statusLabel($upload->getAttribute('provider_deletion_status')),
                'alter_minuten' => $this->ageInMinutes($upload->getAttribute('created_at')),
            ];
        }

        return $rows;
    }

    /**
     * Ueberfaellige temporaere Uploads als Liste, ohne Storage-Key.
     *
     * @return list<array{dokument_id: string, alter_minuten: int|null, loeschversuche: int, fehlerklasse: string|null}>
     */
    public function overdueUploads(int $limit = 50): array
    {
        /** @var list<TemporaryUpload> $uploads */
        $uploads = $this->overdueQuery()
            ->orderBy('expires_at')
            ->limit($limit)
            ->get()
            ->all();

        $rows = [];

        foreach ($uploads as $upload) {
            $documentId = $upload->getAttribute('document_id');
            $error = $upload->getAttribute('last_error');

            $rows[] = [
                'dokument_id' => is_string($documentId) ? $documentId : '',
                'alter_minuten' => $this->ageInMinutes($upload->getAttribute('expires_at')),
                'loeschversuche' => (int) $upload->getAttribute('deletion_attempts'),
                // Nur die Fehlerklasse, niemals ein Pfad oder ein Dateiname.
                'fehlerklasse' => is_string($error) && $error !== '' ? $this->errorClass($error) : null,
            ];
        }

        return $rows;
    }

    // -----------------------------------------------------------------
    // Hilfsmittel
    // -----------------------------------------------------------------

    /**
     * @return Builder<TemporaryUpload>
     */
    private function overdueQuery(): Builder
    {
        return TemporaryUpload::query()
            ->where('is_tombstone', false)
            ->whereNotNull('storage_key')
            ->where('expires_at', '<', Carbon::now());
    }

    private function latestEventFor(string $documentId): ?SourceDeletionEvent
    {
        $event = SourceDeletionEvent::query()
            ->where('document_id', $documentId)
            ->orderByDesc('occurred_at')
            ->first();

        return $event instanceof SourceDeletionEvent ? $event : null;
    }

    private function statusLabel(mixed $status): string
    {
        if ($status instanceof DeletionStatus) {
            return $status->label();
        }

        if (is_string($status) && $status !== '') {
            $enum = DeletionStatus::tryFrom($status);

            return $enum?->label() ?? $status;
        }

        return 'unbekannt';
    }

    private function errorCode(?SourceDeletionEvent $event): ?string
    {
        $code = $event?->getAttribute('error_code');

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * Reduziert eine Fehlermeldung auf eine Klasse. Damit gelangt kein Pfad und
     * kein Dateiname in die Oberflaeche.
     */
    private function errorClass(string $error): string
    {
        $first = strtok($error, ':');
        $class = $first === false ? $error : $first;

        return mb_substr(trim($class), 0, 60);
    }

    private function ageInMinutes(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return (int) Carbon::parse((string) $value)->diffInMinutes(Carbon::now(), true);
    }

    private function ageInHours(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return (int) Carbon::parse((string) $value)->diffInHours(Carbon::now(), true);
    }
}
