<?php

declare(strict_types=1);

namespace App\Domain\Support;

/**
 * Version der deterministischen Berechnungsengine.
 *
 * Die Version wird in jedem Berechnungsergebnis mitgeführt und von der
 * Persistenzschicht in den Calculation-Snapshot geschrieben, damit ein
 * bezahlter Berechnungsstand reproduzierbar bleibt. Jede fachliche Änderung
 * am Rechenweg erfordert eine neue Version.
 */
final class EngineVersion
{
    /**
     * Semantische Version des Rechenwegs.
     */
    public const string CURRENT = '1.0.0';

    /**
     * Verbindliche Intervallsemantik aller Zeiträume dieser Engine.
     */
    public const string PERIOD_SEMANTICS = 'Start- und Endtag inklusive, taggenaue Zählung';

    /**
     * Verfahren zur Verteilung von Rundungsdifferenzen.
     */
    public const string ROUNDING_METHOD = 'Largest Remainder, Gleichstand nach Beteiligtenschlüssel aufsteigend';
}
