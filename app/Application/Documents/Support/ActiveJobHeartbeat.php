<?php

declare(strict_types=1);

namespace App\Application\Documents\Support;

use Closure;

/**
 * Heartbeat des gerade laufenden Teiljobs fuer tiefer liegende Schichten.
 *
 * Die Vertraege DocumentClassifier und DocumentExtractor kennen keinen
 * JobContext. Der Teiljob hinterlegt hier fuer die Dauer seines Laufs eine
 * Funktion, die sein Lease verlaengert; die KI-Anbindung ruft sie vor jedem
 * einzelnen Providerrequest auf. So kann ein langer Aufruf mit mehreren
 * Requests von je bis zu 120 Sekunden das Lease von 300 Sekunden nicht mehr
 * ueberschreiten, und kein zweiter Lauf uebernimmt das Dokument, waehrend der
 * erste noch beim Provider haengt.
 *
 * Der Zustand ist prozessweit, weil ein Cron-Lauf Teiljobs strikt
 * nacheinander abarbeitet. Ausserhalb eines Teiljobs ist keine Funktion
 * hinterlegt und beat() ist wirkungslos.
 */
final class ActiveJobHeartbeat
{
    /**
     * @var (Closure(): bool)|null
     */
    private static ?Closure $beat = null;

    /**
     * @param  Closure(): bool  $beat  verlaengert das Lease, false wenn es verloren ist
     */
    public static function bind(Closure $beat): void
    {
        self::$beat = $beat;
    }

    public static function release(): void
    {
        self::$beat = null;
    }

    /**
     * @return bool false, wenn das Lease inzwischen einem anderen Lauf gehoert
     */
    public static function beat(): bool
    {
        if (self::$beat === null) {
            return true;
        }

        return (self::$beat)();
    }
}
