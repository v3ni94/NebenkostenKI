<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

use App\Enums\DocumentType;

/**
 * Ergebnis der Dokumentklassifikation.
 *
 * Ein nicht zuordenbares Dokument fuehrt zu DocumentType::SONSTIGES und
 * einer manuellen Zuordnungsaufgabe. Der Typ wird nicht geraten.
 *
 * Der Hinweis containsInstructionLikeText stammt aus dem Schema und dient der
 * Sicherheitsprotokollierung. Er bedeutet nicht, dass eine Anweisung befolgt
 * wurde. Dokumentinhalte sind ausschliesslich untrusted data.
 */
final class ClassificationResult
{
    /**
     * @param  list<array{dokumenttyp: string|null, begruendung: string|null}>  $alternatives
     */
    public function __construct(
        public readonly ExtractionResult $extraction,
        public readonly ?DocumentType $documentType,
        public readonly float $confidence,
        public readonly array $alternatives = [],
        public readonly bool $containsInstructionLikeText = false,
    ) {}

    public function status(): AiResultStatus
    {
        return $this->extraction->status;
    }

    public function metadata(): AiCallMetadata
    {
        return $this->extraction->metadata;
    }

    public function isValidated(): bool
    {
        return $this->extraction->isValidated();
    }

    /**
     * Klassifikation ist ausdruecklich zu pruefen, wenn kein Typ erkannt
     * wurde, der Typ SONSTIGES lautet oder die Konfidenz unter dem
     * Schwellenwert liegt.
     */
    public function requiresReview(): bool
    {
        if (! $this->isValidated()) {
            return true;
        }

        if ($this->documentType === null || $this->documentType === DocumentType::SONSTIGES) {
            return true;
        }

        $field = $this->extraction->field('dokumenttyp');

        return $field?->requiresReview ?? true;
    }
}
