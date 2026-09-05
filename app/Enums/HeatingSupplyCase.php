<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Versorgungsfall der Heizkosten nach Abschnitt 12.3.
 *
 * Bei DEZENTRAL werden keine Heizkosten als Vermieterkosten angesetzt.
 */
enum HeatingSupplyCase: string
{
    case EXTERN_ABGERECHNET = 'EXTERN_ABGERECHNET';
    case ZENTRAL_OHNE_EXTERN = 'ZENTRAL_OHNE_EXTERN';
    case DEZENTRAL = 'DEZENTRAL';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::EXTERN_ABGERECHNET => 'Externe Heizkostenabrechnung',
            self::ZENTRAL_OHNE_EXTERN => 'Zentralheizung ohne externe Abrechnung',
            self::DEZENTRAL => 'Dezentrale Versorgung durch den Mieter',
        };
    }
}
