<?php

declare(strict_types=1);

namespace App\Application\Privacy;

use App\Application\Account\AuditRecorder;
use App\Application\Privacy\Dto\DataExportResult;
use App\Enums\GeneratedDocumentKind;
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
 * NUTZERBEZUG: Der Export enthält Kontodaten des Antragstellers und Daten
 * aller seiner Mandanten. Er wird deshalb über requested_by_user_id an den
 * anfordernden Nutzer gebunden. In einem geteilten Mandanten sieht und lädt
 * nur er selbst den Export, nicht jedes Mitglied (PrivacyController).
 *
 * EIN EXPORT JE NUTZER: Jede Anforderung erzeugt einen neuen, auf den
 * Zeitpunkt bezogenen Export. Ältere Exporte desselben Nutzers werden dabei
 * samt Datei entfernt. Ein Export ist eine Vollkopie aller PDFs des Kontos;
 * ohne diese Grenze könnte ein einzelner Nutzer den Speicher des Tarifs mit
 * Wiederholungen füllen. Zusätzlich ist die Route gedrosselt
 * (RateLimiter datenexport in AppServiceProvider).
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

        $entfernt = $this->removePreviousExports($user);

        /** @var GeneratedDocument $dokument */
        $dokument = GeneratedDocument::query()->create([
            'organization_id' => $organizationId,
            'requested_by_user_id' => (string) $user->getKey(),
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
                'removed_previous_exports' => $entfernt,
            ],
        );

        return new DataExportResult(
            $dokument,
            $referenz->byteSize,
            $paket['entries'],
            $paket['counts'],
        );
    }

    /**
     * Entfernt alle früheren Exporte des Nutzers samt Datei, unabhängig vom
     * Mandanten, unter dem sie abgelegt wurden.
     */
    private function removePreviousExports(User $user): int
    {
        /** @var list<GeneratedDocument> $alte */
        $alte = GeneratedDocument::query()
            ->where('kind', GeneratedDocumentKind::DSGVO_EXPORT->value)
            ->where('requested_by_user_id', $user->getKey())
            ->get()
            ->all();

        foreach ($alte as $export) {
            $pfad = $export->getAttribute('storage_path');

            if (is_string($pfad) && $pfad !== '' && $this->artifacts->exists($pfad)) {
                $this->artifacts->delete($pfad);
            }

            $export->delete();
        }

        return count($alte);
    }
}
