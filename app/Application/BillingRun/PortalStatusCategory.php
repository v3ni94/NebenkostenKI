<?php

declare(strict_types=1);

namespace App\Application\BillingRun;

/**
 * Die vier Statuskategorien der Oberflaeche.
 *
 * Vorgabe des Masterprompts, Abschnitt 9: Ein Dashboard zeigt statt technischer
 * Fehlermeldungen eine klare Liste: Erledigt, Bitte pruefen, Fehlt noch und
 * Blockiert die Abrechnung.
 *
 * Die Werte sind die Anzeigetexte selbst. Sie werden nicht persistiert, deshalb
 * ist bewusst kein PHP-Enum in App\Enums angelegt worden. In App\Enums liegen
 * ausschliesslich persistierte Statuswerte.
 *
 * Ein Status wird nie allein ueber Farbe kommuniziert. Die Kategorie steht
 * immer als Text daneben, die Variante steuert nur zusaetzlich Farbe und
 * Symbol der Komponente x-hvm.badge.
 */
final class PortalStatusCategory
{
    public const ERLEDIGT = 'Erledigt';

    public const BITTE_PRUEFEN = 'Bitte prüfen';

    public const FEHLT_NOCH = 'Fehlt noch';

    public const BLOCKIERT = 'Blockiert die Abrechnung';

    /**
     * Reihenfolge fuer Gruppierungen und Zaehler auf dem Dashboard: das
     * Dringendste zuerst.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::BLOCKIERT,
            self::BITTE_PRUEFEN,
            self::FEHLT_NOCH,
            self::ERLEDIGT,
        ];
    }

    /**
     * Variante der Statuskomponenten des Designsystems.
     */
    public static function variant(string $category): string
    {
        return match ($category) {
            self::ERLEDIGT => 'success',
            self::BITTE_PRUEFEN => 'warning',
            self::BLOCKIERT => 'error',
            default => 'info',
        };
    }
}
