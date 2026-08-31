<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Herkunft einer Kostenposition.
 */
enum CostItemSource: string
{
    case KI_EXTRAKTION = 'KI_EXTRAKTION';
    case MANUELL = 'MANUELL';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::KI_EXTRAKTION => 'Automatisch ausgelesen',
            self::MANUELL => 'Manuell erfasst',
        };
    }
}
