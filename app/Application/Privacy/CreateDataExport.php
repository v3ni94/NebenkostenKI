<?php

declare(strict_types=1);

namespace App\Application\Privacy;

use App\Application\Account\AuditRecorder;
use App\Application\Privacy\Dto\DataExportResult;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentVariant;
use App\Models\GeneratedDocument;
use App\Models\Organization;
use App\Models\User;
use App\Services\Storage\ArtifactStorage;
use App\Services\Storage\ArtifactType;

/**
 * Use Case: Datenexport auf Anforderung des Nutzers (Masterprompt 19).
 *
 * Der Export wird als Artefakt vom Typ DSGVO_EXPORT abgelegt. Damit greifen
 * ohne Zusatzaufwand alle bestehenden Sperren: Es gibt keinen öffentlichen
 * Pfad, die Magic-Byte-Prüfung von ArtifactStorage lässt nur ein echtes ZIP zu
 * und die Ablage liegt außerhalb des Webroots.
 *
 * Der Vorgang ist ausdrücklich NICHT idempotent: Jede Anforderung erzeugt einen
 * neuen, auf den Zeitpunkt bezogenen Export. Alte Exporte bleiben bestehen, bis
 * die Aufbewahrungsfrist für erzeugte PDFs greift.
 */
final class CreateDataExport
{
    public function __construct(
        private readonly DataExportBuilder $builder,
        private readonly ArtifactStorage $artifacts,
        private readonly AuditRecorder $audit,
    ) {}

    public function __invoke(User $user, Organization $organization): DataExportResult
    {
        $paket = $this->builder->build($user);

        $organizationId = (string) $organization->getKey();

        $referenz = $this->artifacts->put(
            ArtifactType::DSGVO_EXPORT,
            $organizationId,
            $paket['contents'],
        );

        /** @var GeneratedDocument $dokument */
        $dokument = GeneratedDocument::query()->create([
            'organization_id' => $organizationId,
            'kind' => ArtifactType::DSGVO_EXPORT->kind(),
            'variant' => GeneratedDocumentVariant::FINAL,
            'status' => GeneratedDocumentStatus::AKTIV,
            'storage_disk' => $referenz->disk,
            'storage_path' => $referenz->path,
            'byte_size' => $referenz->byteSize,
            'sha256' => $referenz->sha256,
            'generated_at' => now(),
        ]);

        $this->audit->record(
            action: 'privacy.export.created',
            subject: $dokument,
            actor: $user,
            organization: $organization,
            metadata: [
                'byte_size' => $referenz->byteSize,
                'entry_count' => count($paket['entries']),
            ],
        );

        return new DataExportResult(
            $dokument,
            $referenz->byteSize,
            $paket['entries'],
            $paket['counts'],
        );
    }
}
