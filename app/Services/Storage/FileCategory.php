<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Technische Gruppe einer Eingabedatei nach Abschnitt 6.1.
 */
enum FileCategory: string
{
    case PDF = 'PDF';
    case BILD = 'BILD';
    case HEIC = 'HEIC';
    case TABELLE = 'TABELLE';
    case ARCHIV = 'ARCHIV';

    public function label(): string
    {
        return match ($this) {
            self::PDF => 'PDF-Dokument',
            self::BILD => 'Bilddatei',
            self::HEIC => 'HEIC-Bild',
            self::TABELLE => 'Tabelle',
            self::ARCHIV => 'Archiv',
        };
    }

    /**
     * HEIC benoetigt vor der Auswertung eine serverseitige Umwandlung.
     */
    public function requiresConversion(): bool
    {
        return $this === self::HEIC;
    }

    /**
     * Archive werden nicht selbst ausgewertet, sondern eintragsweise geprueft
     * und in einzelne Dokumente aufgeteilt.
     */
    public function isContainer(): bool
    {
        return $this === self::ARCHIV;
    }
}
