<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Heating;

/**
 * Heizkostenfälle des Pflichtenhefts (Abschnitt 12.3).
 *
 * EXTERNAL_STATEMENT          Fall A: externe Heizkostenabrechnung liegt vor.
 *                             Einzelbeträge je Einheit werden direkt
 *                             zugeordnet und gegen die Gesamtsumme geprüft.
 * CENTRAL_WITHOUT_STATEMENT   Fall B: Zentralheizung ohne externen
 *                             Abrechner. Eine Eigenberechnung nach
 *                             Heizkostenverordnung ist bewusst nicht Teil des
 *                             Leistungsumfangs. Der Anwender erfasst die
 *                             selbst ermittelten Beträge je Einheit; sie
 *                             werden unverändert als Direktzuordnung
 *                             übernommen.
 * DECENTRALIZED               Fall C: dezentrale Versorgung. Der Mieter
 *                             bezieht Energie direkt; es werden keine
 *                             Heizkosten als Vermieterkosten angesetzt.
 */
enum HeatingSupplyType: string
{
    case EXTERNAL_STATEMENT = 'EXTERNAL_STATEMENT';
    case CENTRAL_WITHOUT_STATEMENT = 'CENTRAL_WITHOUT_STATEMENT';
    case DECENTRALIZED = 'DECENTRALIZED';

    public function label(): string
    {
        return match ($this) {
            self::EXTERNAL_STATEMENT => 'externe Heizkostenabrechnung',
            self::CENTRAL_WITHOUT_STATEMENT => 'Zentralheizung ohne externe Abrechnung',
            self::DECENTRALIZED => 'dezentrale Versorgung',
        };
    }

    /**
     * Erzeugt dieser Fall Heizkostenzeilen in der Mieterabrechnung?
     */
    public function producesHeatingLines(): bool
    {
        return $this !== self::DECENTRALIZED;
    }
}
