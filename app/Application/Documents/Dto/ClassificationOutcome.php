<?php

declare(strict_types=1);

namespace App\Application\Documents\Dto;

use App\Enums\DocumentType;

/**
 * Ergebnis der Dokumentklassifikation, wie es diese Anwendungsschicht
 * benoetigt.
 *
 * Es werden nur Dokumenttyp und Konfidenz uebernommen, kein Rohtext und keine
 * Providerantwort (Abschnitt 6.4).
 */
final class ClassificationOutcome
{
    private function __construct(
        public readonly bool $successful,
        public readonly ?DocumentType $documentType = null,
        public readonly ?float $confidence = null,
        public readonly ?string $errorCode = null,
    ) {}

    public static function classified(DocumentType $type, ?float $confidence = null): self
    {
        return new self(true, $type, $confidence);
    }

    /**
     * Der Typ konnte nicht bestimmt werden. Es wird NICHT geraten; das Dokument
     * bleibt SONSTIGES und der Nutzer ordnet es manuell zu (Grundsatz 5).
     */
    public static function undetermined(string $errorCode): self
    {
        return new self(false, DocumentType::SONSTIGES, null, $errorCode);
    }

    public static function failed(string $errorCode): self
    {
        return new self(false, null, null, $errorCode);
    }
}
