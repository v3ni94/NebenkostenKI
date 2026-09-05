<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ergebnis eines KI-Aufrufs. Rohe Prompts und Antworten werden nie gespeichert.
 */
enum AiCallStatus: string
{
    case ERFOLGREICH = 'ERFOLGREICH';
    case SCHEMA_FEHLER = 'SCHEMA_FEHLER';
    case RATE_LIMIT = 'RATE_LIMIT';
    case TECHNISCHER_FEHLER = 'TECHNISCHER_FEHLER';
    case LIMIT_UEBERSCHRITTEN = 'LIMIT_UEBERSCHRITTEN';
    case BLOCKIERT = 'BLOCKIERT';
    case ABGEBROCHEN = 'ABGEBROCHEN';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::ERFOLGREICH => 'Erfolgreich',
            self::SCHEMA_FEHLER => 'Schemaverletzung',
            self::RATE_LIMIT => 'Ratenbegrenzung',
            self::TECHNISCHER_FEHLER => 'Technischer Fehler',
            self::LIMIT_UEBERSCHRITTEN => 'Kostenlimit überschritten',
            self::BLOCKIERT => 'Durch Datenschutzsperre blockiert',
            self::ABGEBROCHEN => 'Abgebrochen',
        };
    }
}
