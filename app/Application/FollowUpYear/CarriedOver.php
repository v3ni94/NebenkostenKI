<?php

declare(strict_types=1);

namespace App\Application\FollowUpYear;

/**
 * Sichtbare Kennzeichnung uebernommener Felder (Masterprompt 8.3).
 *
 * Der Hinweis ist wortgleich mit App\Enums\ValueSource::VORJAHR und
 * App\Enums\AllocationKeySource::VORJAHR, damit Oberflaeche, PDF und Datenmodell
 * denselben Text zeigen.
 */
final class CarriedOver
{
    public const HINWEIS = 'Aus Vorjahr übernommen';

    /**
     * Feldarten, die aus dem letzten finalisierten Lauf uebernommen werden.
     *
     * @var list<string>
     */
    public const FELDER = [
        'Objektdaten',
        'Eigentümerdaten',
        'Einheiten mit Flächen und individuellen Schlüsseln',
        'Laufende Mietverhältnisse',
        'Verteilerschlüssel',
        'Kostenkategorien',
        'Bankverbindung und Absenderdaten',
    ];

    /**
     * Angaben, die fuer das neue Jahr erneut erkannt oder bestaetigt werden
     * muessen. Sie werden ausdruecklich NICHT uebernommen.
     *
     * @var list<string>
     */
    public const ERNEUT_ZU_BESTAETIGEN = [
        'Belege und Kostenpositionen',
        'Mieterwechsel',
        'Vorauszahlungen',
        'Zählerstände',
        'Heizkosten',
    ];
}
