<?php

declare(strict_types=1);

namespace App\Application\Privacy\Dto;

/**
 * Ergebnis einer endgültigen Kontolöschung.
 */
final class AccountDeletionReport
{
    public function __construct(
        public readonly bool $executed,
        public readonly int $deletedOrganizations = 0,
        public readonly int $decoupledInvoices = 0,
        public readonly int $retainedInvoiceDocuments = 0,
        public readonly int $deletedGeneratedDocuments = 0,
        public readonly int $deletedArtifacts = 0,
        public readonly int $failedArtifacts = 0,
        public readonly bool $alreadyDeleted = false,
    ) {}

    public static function skipped(): self
    {
        return new self(false, alreadyDeleted: true);
    }

    public function summary(): string
    {
        if (! $this->executed) {
            return 'Keine Löschung ausgeführt, der Vorgang war bereits abgeschlossen.';
        }

        return sprintf(
            'Konto gelöscht: %d Mandanten entfernt, %d Rechnungen entkoppelt, %d Rechnungsbelege '
            .'erhalten, %d erzeugte PDFs gelöscht, %d Artefaktdateien entfernt, %d Fehler.',
            $this->deletedOrganizations,
            $this->decoupledInvoices,
            $this->retainedInvoiceDocuments,
            $this->deletedGeneratedDocuments,
            $this->deletedArtifacts,
            $this->failedArtifacts,
        );
    }
}
