<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Abrechnungsweg.
 *
 * Ein Wechsel des Weges loescht keine strukturierten Extraktionsdaten.
 */
enum BillingMode: string
{
    case QUICK_CONDO = 'QUICK_CONDO';
    case FULL_PROPERTY = 'FULL_PROPERTY';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::QUICK_CONDO => 'Schnellabrechnung Eigentumswohnung',
            self::FULL_PROPERTY => 'Vollständige Objektabrechnung',
        };
    }
}
