<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status einer Leistungsrechnung der Hausverwaltung Mueller GmbH.
 *
 * Rechnungen werden nicht nachtraeglich ueberschrieben. Ein Storno erfolgt
 * ausschliesslich ueber eine Stornorechnung mit Referenz.
 */
enum InvoiceStatus: string
{
    case ENTWURF = 'ENTWURF';
    case FESTGESCHRIEBEN = 'FESTGESCHRIEBEN';
    case BEZAHLT = 'BEZAHLT';
    case STORNIERT = 'STORNIERT';
    case STORNORECHNUNG = 'STORNORECHNUNG';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::ENTWURF => 'Entwurf',
            self::FESTGESCHRIEBEN => 'Festgeschrieben',
            self::BEZAHLT => 'Bezahlt',
            self::STORNIERT => 'Storniert',
            self::STORNORECHNUNG => 'Stornorechnung',
        };
    }

    /**
     * Rechnung ist unveraenderlich festgeschrieben.
     */
    public function isImmutable(): bool
    {
        return $this !== self::ENTWURF;
    }
}
