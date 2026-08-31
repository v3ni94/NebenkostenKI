<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status eines erzeugten Artefakts.
 */
enum GeneratedDocumentStatus: string
{
    case AKTIV = 'AKTIV';
    case ERSETZT = 'ERSETZT';
    case UNGUELTIG = 'UNGUELTIG';
    case GELOESCHT = 'GELOESCHT';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::AKTIV => 'Aktiv',
            self::ERSETZT => 'Ersetzt',
            self::UNGUELTIG => 'Ungültig',
            self::GELOESCHT => 'Gelöscht',
        };
    }
}
