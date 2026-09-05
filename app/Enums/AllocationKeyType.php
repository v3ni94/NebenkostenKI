<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Verteilerschluessel nach Abschnitt 9 Schritt 8.
 *
 * WEG-Schluessel und mietvertraglicher Umlageschluessel werden nicht
 * stillschweigend gleichgesetzt.
 */
enum AllocationKeyType: string
{
    case WOHNFLAECHE = 'WOHNFLAECHE';
    case BEHEIZTE_WOHNFLAECHE = 'BEHEIZTE_WOHNFLAECHE';
    case MEA = 'MEA';
    case PERSONEN = 'PERSONEN';
    case PERSONENTAGE = 'PERSONENTAGE';
    case EINHEITEN = 'EINHEITEN';
    case VERBRAUCH = 'VERBRAUCH';
    case DIREKT = 'DIREKT';
    case INDIVIDUELL_1 = 'INDIVIDUELL_1';
    case INDIVIDUELL_2 = 'INDIVIDUELL_2';
    case INDIVIDUELL_3 = 'INDIVIDUELL_3';
    case INDIVIDUELL_4 = 'INDIVIDUELL_4';
    case INDIVIDUELL_5 = 'INDIVIDUELL_5';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::WOHNFLAECHE => 'Wohnfläche',
            self::BEHEIZTE_WOHNFLAECHE => 'Beheizte Wohnfläche',
            self::MEA => 'Miteigentumsanteile',
            self::PERSONEN => 'Personen',
            self::PERSONENTAGE => 'Personentage',
            self::EINHEITEN => 'Einheiten',
            self::VERBRAUCH => 'Verbrauch',
            self::DIREKT => 'Direkte Zuordnung',
            self::INDIVIDUELL_1 => 'Individueller Schlüssel 1',
            self::INDIVIDUELL_2 => 'Individueller Schlüssel 2',
            self::INDIVIDUELL_3 => 'Individueller Schlüssel 3',
            self::INDIVIDUELL_4 => 'Individueller Schlüssel 4',
            self::INDIVIDUELL_5 => 'Individueller Schlüssel 5',
        };
    }

    /**
     * Schluessel benoetigt Ablesewerte und damit bei Nutzerwechsel eine
     * Zwischenablesung.
     */
    public function requiresMeterReadings(): bool
    {
        return $this === self::VERBRAUCH;
    }

    /**
     * Schluessel wird ohne Zeitanteil direkt einer Einheit zugeordnet.
     */
    public function isDirect(): bool
    {
        return $this === self::DIREKT;
    }

    /**
     * Individuelle Schluessel 1 bis 5 werden je Objekt benannt.
     */
    public function isIndividual(): bool
    {
        return match ($this) {
            self::INDIVIDUELL_1, self::INDIVIDUELL_2, self::INDIVIDUELL_3,
            self::INDIVIDUELL_4, self::INDIVIDUELL_5 => true,
            default => false,
        };
    }
}
