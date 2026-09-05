<?php

declare(strict_types=1);

namespace App\Domain\Calculation;

/**
 * Einordnung nach § 35a EStG (Pflichtenheft Abschnitt 12.4).
 *
 * Nur nachgewiesene Arbeits-, Maschinen- und Fahrtkosten beziehungsweise
 * ausdrücklich ausgewiesene begünstigte Bestandteile werden übernommen.
 * Materialkosten werden nicht automatisch als Lohnanteil ausgegeben; ist der
 * Lohnanteil nicht ausgewiesen, bleibt der Betrag null und die Zeile wird
 * gekennzeichnet.
 *
 * Domain-eigenes Enum; die Persistenzschicht bildet ihre Werte darauf ab.
 */
enum TaxBenefitCategory: string
{
    case NONE = 'NONE';
    case HOUSEHOLD_SERVICE = 'HOUSEHOLD_SERVICE';
    case CRAFTSMAN_SERVICE = 'CRAFTSMAN_SERVICE';

    public function label(): string
    {
        return match ($this) {
            self::NONE => 'nicht begünstigt',
            self::HOUSEHOLD_SERVICE => 'haushaltsnahe Dienstleistung',
            self::CRAFTSMAN_SERVICE => 'Handwerkerleistung',
        };
    }

    public function isBenefited(): bool
    {
        return $this !== self::NONE;
    }
}
