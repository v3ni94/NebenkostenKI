<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Art einer Messeinrichtung.
 */
enum MeterType: string
{
    case KALTWASSER = 'KALTWASSER';
    case WARMWASSER = 'WARMWASSER';
    case WAERMEMENGE = 'WAERMEMENGE';
    case HEIZKOSTENVERTEILER = 'HEIZKOSTENVERTEILER';
    case ALLGEMEINSTROM = 'ALLGEMEINSTROM';
    case GAS = 'GAS';
    case SONSTIGES = 'SONSTIGES';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::KALTWASSER => 'Kaltwasserzähler',
            self::WARMWASSER => 'Warmwasserzähler',
            self::WAERMEMENGE => 'Wärmemengenzähler',
            self::HEIZKOSTENVERTEILER => 'Heizkostenverteiler',
            self::ALLGEMEINSTROM => 'Stromzähler',
            self::GAS => 'Gaszähler',
            self::SONSTIGES => 'Sonstige Messeinrichtung',
        };
    }
}
