<?php

declare(strict_types=1);

namespace App\Services\Pdf\View;

use DateTimeImmutable;

/**
 * Eintrag der Dokumentenübersicht der Eigentümerübersicht (Abschnitt 14.2).
 *
 * Ausgewiesen werden Art, Variante, Erzeugungszeitpunkt und Prüfsumme der
 * erzeugten Dateien, nicht deren Speicherpfad.
 */
final readonly class DocumentIndexEntry
{
    public function __construct(
        public string $kindLabel,
        public string $variantLabel,
        public ?string $recipientLabel = null,
        public ?DateTimeImmutable $generatedAt = null,
        public ?string $sha256 = null,
        public ?int $pageCount = null,
    ) {}

    /**
     * Verkürzte Prüfsumme für die Anzeige, damit die Tabelle lesbar bleibt.
     */
    public function shortSha256(): string
    {
        if ($this->sha256 === null || $this->sha256 === '') {
            return '';
        }

        return substr($this->sha256, 0, 16).'…';
    }
}
