<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Objektart. Steuert Vorschlaege, nicht die Berechnung selbst.
 */
enum PropertyKind: string
{
    case EIGENTUMSWOHNUNG = 'EIGENTUMSWOHNUNG';
    case MEHRFAMILIENHAUS = 'MEHRFAMILIENHAUS';
    case GEMISCHTE_NUTZUNG = 'GEMISCHTE_NUTZUNG';
    case GEWERBEOBJEKT = 'GEWERBEOBJEKT';
    case SONSTIGES = 'SONSTIGES';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::EIGENTUMSWOHNUNG => 'Eigentumswohnung',
            self::MEHRFAMILIENHAUS => 'Mehrfamilienhaus',
            self::GEMISCHTE_NUTZUNG => 'Gemischt genutztes Objekt',
            self::GEWERBEOBJEKT => 'Gewerbeobjekt',
            self::SONSTIGES => 'Sonstiges Objekt',
        };
    }
}
