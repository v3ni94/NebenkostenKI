<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/**
 * Verteilerschlüsseltypen der Domain-Schicht (Abschnitt 9, Schritt 8).
 *
 * Bewusst ein DOMAIN-eigenes Enum: die Persistenzschicht führt ihre eigenen
 * Enums bzw. Spaltenwerte und bildet sie auf dieses Enum ab. Die Domain hat
 * keine Abhängigkeit zu App\Enums, Eloquent oder Laravel.
 *
 * WEG-Schlüssel und mietvertraglicher Umlageschlüssel werden nicht
 * stillschweigend gleichgesetzt; welcher Typ gilt, entscheidet die
 * Anwendungsschicht anhand bestätigter Nutzereingaben.
 */
enum AllocationKeyType: string
{
    case LIVING_AREA = 'LIVING_AREA';
    case HEATED_LIVING_AREA = 'HEATED_LIVING_AREA';
    case CO_OWNERSHIP_SHARE = 'CO_OWNERSHIP_SHARE';
    case PERSONS = 'PERSONS';
    case PERSON_DAYS = 'PERSON_DAYS';
    case UNITS = 'UNITS';
    case CONSUMPTION = 'CONSUMPTION';
    case DIRECT_ASSIGNMENT = 'DIRECT_ASSIGNMENT';
    case INDIVIDUAL_1 = 'INDIVIDUAL_1';
    case INDIVIDUAL_2 = 'INDIVIDUAL_2';
    case INDIVIDUAL_3 = 'INDIVIDUAL_3';
    case INDIVIDUAL_4 = 'INDIVIDUAL_4';
    case INDIVIDUAL_5 = 'INDIVIDUAL_5';

    /**
     * Deutsche Bezeichnung für Anzeige und PDF.
     */
    public function label(): string
    {
        return match ($this) {
            self::LIVING_AREA => 'Wohnfläche',
            self::HEATED_LIVING_AREA => 'beheizte Wohnfläche',
            self::CO_OWNERSHIP_SHARE => 'Miteigentumsanteile',
            self::PERSONS => 'Personen',
            self::PERSON_DAYS => 'Personentage',
            self::UNITS => 'Einheiten',
            self::CONSUMPTION => 'Verbrauch',
            self::DIRECT_ASSIGNMENT => 'Direktzuordnung',
            self::INDIVIDUAL_1 => 'Individueller Schlüssel 1',
            self::INDIVIDUAL_2 => 'Individueller Schlüssel 2',
            self::INDIVIDUAL_3 => 'Individueller Schlüssel 3',
            self::INDIVIDUAL_4 => 'Individueller Schlüssel 4',
            self::INDIVIDUAL_5 => 'Individueller Schlüssel 5',
        };
    }

    /**
     * Bezugsebene des Schlüssels.
     *
     * UNIT: Zähler je Einheit, die zeitliche Aufteilung innerhalb der Einheit
     * erfolgt zusätzlich über den Zeitfaktor.
     * OCCUPANCY: Zähler je Nutzungszeitraum (Mietverhältnis oder Leerstand);
     * die Zeitgewichtung ist bereits im Zähler enthalten.
     */
    public function scope(): AllocationKeyScope
    {
        return match ($this) {
            self::PERSON_DAYS, self::CONSUMPTION, self::DIRECT_ASSIGNMENT => AllocationKeyScope::OCCUPANCY,
            default => AllocationKeyScope::UNIT,
        };
    }

    /**
     * Maßeinheit des Zählers für Erklärungstexte.
     */
    public function unitOfMeasure(): string
    {
        return match ($this) {
            self::LIVING_AREA, self::HEATED_LIVING_AREA => 'm²',
            self::PERSONS => 'Personen',
            self::PERSON_DAYS => 'Personentage',
            self::UNITS => 'Einheiten',
            self::DIRECT_ASSIGNMENT => 'EUR',
            default => '',
        };
    }
}
