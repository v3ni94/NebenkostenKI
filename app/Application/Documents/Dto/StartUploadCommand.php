<?php

declare(strict_types=1);

namespace App\Application\Documents\Dto;

use App\Enums\DocumentType;

/**
 * Eingabe zum Start eines Chunk-Uploads.
 *
 * DATENSCHUTZ: Der Originaldateiname ist bewusst NICHT Teil dieses Objekts.
 * Der Controller entnimmt dem Uploaddialog nur die Dateiendung und verwirft den
 * Namen sofort. Damit erreicht der Name die Anwendungsschicht gar nicht erst
 * und kann weder in einen Queue-Payload noch in ein Protokoll gelangen
 * (Abschnitt 6.3 Schritt 3).
 *
 * Die Kategorie ist optional. Der Nutzer muss nichts einordnen; das System
 * klassifiziert selbst (Abschnitt 9, Schritt 2).
 */
final class StartUploadCommand
{
    public function __construct(
        public readonly string $extension,
        public readonly int $byteSize,
        public readonly ?DocumentType $suggestedType = null,
    ) {}
}
