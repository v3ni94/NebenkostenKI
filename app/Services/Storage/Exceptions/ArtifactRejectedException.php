<?php

declare(strict_types=1);

namespace App\Services\Storage\Exceptions;

use App\Services\Storage\UploadErrorCode;
use RuntimeException;

/**
 * Technische Sperre der Artefaktablage.
 *
 * Wird ausgeloest, wenn versucht wird, etwas anderes als ein vom System
 * erzeugtes Ergebnisartefakt auf der dauerhaften Disk abzulegen. Die Ausnahme
 * ist ein Programmierfehler, keine Nutzermeldung: Originaluploads duerfen
 * niemals auf sftp oder s3 gelangen (ADR-007, Abschnitt 3.4).
 */
class ArtifactRejectedException extends RuntimeException
{
    final public function __construct(
        public readonly UploadErrorCode $errorCode,
        string $reason,
    ) {
        parent::__construct($reason);
    }

    public static function disk(string $disk): self
    {
        return new self(
            UploadErrorCode::ARTEFAKT_DISK_UNZULAESSIG,
            sprintf(
                'Die Disk "%s" ist fuer Ergebnisartefakte nicht zugelassen. Originaluploads gehoeren '
                .'ausschliesslich auf die Disk "temporary_uploads".',
                $disk
            )
        );
    }

    public static function contents(string $expectedSignature): self
    {
        return new self(
            UploadErrorCode::ARTEFAKT_UNZULAESSIG,
            sprintf(
                'Der Inhalt entspricht nicht dem erwarteten Artefaktformat (%s). Es duerfen nur vom '
                .'System erzeugte Artefakte dauerhaft gespeichert werden.',
                $expectedSignature
            )
        );
    }

    public static function sourceDisk(string $disk): self
    {
        return new self(
            UploadErrorCode::ARTEFAKT_UNZULAESSIG,
            sprintf(
                'Ein Kopiervorgang von der Disk "%s" in die Artefaktablage ist gesperrt. Quelldateien '
                .'werden nach der Auswertung geloescht und nicht dauerhaft gespeichert.',
                $disk
            )
        );
    }
}
