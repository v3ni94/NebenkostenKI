<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

/**
 * Anfrage zur Dokumentklassifikation nach Abschnitt 6.2.
 *
 * Fuer die Klassifikation wird bewusst das guenstigere Extraktionsmodell
 * verwendet (Abschnitt 13.8: kleinere Modelle fuer Klassifikation und
 * einfache Extraktion).
 */
final class ClassifyDocumentRequest
{
    public function __construct(
        public readonly DocumentPayload $document,
        public readonly AiRequestContext $context,
    ) {}
}
