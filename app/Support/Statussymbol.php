<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Verbindliche Zuordnung von Statusvariante zu Symbol (docs/designsystem.md,
 * Abschnitt 4.9).
 *
 * Einzige Quelle für x-hvm.alert, x-hvm.badge, x-hvm.stat und die Gruppen des
 * Prüfberichts, damit dieselbe Kategorie überall dasselbe Zeichen trägt:
 * Erledigt check-circle, Bitte prüfen eye, Fehlt noch inbox, Blockiert die
 * Abrechnung alert. Ein Status wird nie allein über Farbe kommuniziert; das
 * Symbol ergänzt das ausgeschriebene Statuswort.
 */
final class Statussymbol
{
    public const SUCCESS = 'check-circle';

    public const WARNING = 'eye';

    public const INFO = 'inbox';

    public const ERROR = 'alert';

    /**
     * Symbol zur Variante; null für Varianten ohne Statusbedeutung (neutral, akzent).
     */
    public static function fuer(string $variante): ?string
    {
        return match ($variante) {
            'success' => self::SUCCESS,
            'warning' => self::WARNING,
            'info' => self::INFO,
            'error' => self::ERROR,
            default => null,
        };
    }
}
