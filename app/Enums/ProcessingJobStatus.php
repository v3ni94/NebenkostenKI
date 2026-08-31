<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status eines Teiljobs der datenbankgestuetzten Queue.
 *
 * Jobs sind idempotent und wiederanlaufbar, Lease und Heartbeat verhindern
 * Doppelverarbeitung in Profil A ohne dauerhaften Worker.
 */
enum ProcessingJobStatus: string
{
    case BEREIT = 'BEREIT';
    case GELEAST = 'GELEAST';
    case ERFOLGREICH = 'ERFOLGREICH';
    case FEHLGESCHLAGEN = 'FEHLGESCHLAGEN';
    case DEAD_LETTER = 'DEAD_LETTER';
    case ABGEBROCHEN = 'ABGEBROCHEN';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::BEREIT => 'Bereit',
            self::GELEAST => 'In Arbeit',
            self::ERFOLGREICH => 'Erfolgreich',
            self::FEHLGESCHLAGEN => 'Fehlgeschlagen',
            self::DEAD_LETTER => 'Endgültig fehlgeschlagen',
            self::ABGEBROCHEN => 'Abgebrochen',
        };
    }
}
