<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * Bezugsebene eines Verteilerschlüssels.
 *
 * UNIT      Der Zähler gehört zur Einheit (Wohnfläche, MEA, Einheiten,
 *           Personen, individuelle Schlüssel). Die Aufteilung zwischen
 *           mehreren Mietverhältnissen derselben Einheit erfolgt zusätzlich
 *           über den Zeitfaktor Nutzungstage / Tage des Abrechnungszeitraums.
 * OCCUPANCY Der Zähler gehört unmittelbar zum Nutzungszeitraum
 *           (Personentage, Verbrauch, Direktzuordnung). Die Zeitgewichtung
 *           ist bereits enthalten, der Zeitfaktor ist deshalb exakt 1.
 */
enum AllocationKeyScope: string
{
    case UNIT = 'UNIT';
    case OCCUPANCY = 'OCCUPANCY';
}
