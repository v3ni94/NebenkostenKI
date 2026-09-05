<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Einordnung nach Paragraf 35a EStG.
 *
 * Nur nachgewiesene Arbeits-, Maschinen- und Fahrtkosten. Materialkosten
 * werden niemals automatisch als beguenstigter Lohnanteil ausgegeben.
 */
enum Paragraph35aType: string
{
    case NONE = 'NONE';
    case HAUSHALTSNAHE_DIENSTLEISTUNG = 'HAUSHALTSNAHE_DIENSTLEISTUNG';
    case HANDWERKERLEISTUNG = 'HANDWERKERLEISTUNG';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::NONE => 'Nicht begünstigt',
            self::HAUSHALTSNAHE_DIENSTLEISTUNG => 'Haushaltsnahe Dienstleistung',
            self::HANDWERKERLEISTUNG => 'Handwerkerleistung',
        };
    }
}
