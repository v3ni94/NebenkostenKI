<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Art einer Datei im Kurzzeitbereich.
 *
 * Alle Arten liegen unter demselben zufaelligen Praefix eines Uploads und
 * werden gemeinsam geloescht. Dadurch kann keine Ableitung uebersehen werden:
 * Seitenbilder, Konvertierungen und der vollstaendige OCR-Text verschwinden
 * zwingend mit dem Original (Abschnitt 6.3 Schritt 15).
 */
enum TemporaryFileKind: string
{
    case CHUNK = 'chunks';
    case ORIGINAL = 'original';
    case SEITENBILD = 'seitenbilder';
    case KONVERTIERUNG = 'konvertierungen';
    case OCR_TEXT = 'ocr';
    case ARCHIV_EINTRAG = 'archiveintraege';

    public function label(): string
    {
        return match ($this) {
            self::CHUNK => 'Dateiabschnitt',
            self::ORIGINAL => 'Originaldatei',
            self::SEITENBILD => 'Seitenbild',
            self::KONVERTIERUNG => 'Konvertierung',
            self::OCR_TEXT => 'Textauszug',
            self::ARCHIV_EINTRAG => 'Archiveintrag',
        };
    }
}
