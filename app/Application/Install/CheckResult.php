<?php

declare(strict_types=1);

namespace App\Application\Install;

/**
 * Ergebnis einer Zeile des Konfigurationschecks.
 *
 * VERBINDLICH: Meldung und Handlungsanweisung enthalten nie ein Secret, keinen
 * Hostnamen, Benutzernamen oder Pfad aus der Konfiguration. Zulaessig sind der
 * Zustand, eine Versionsangabe und der Klassenname einer Ausnahme.
 */
final readonly class CheckResult
{
    public const string OK = 'OK';

    public const string WARNUNG = 'WARNUNG';

    public const string FEHLER = 'FEHLER';

    public function __construct(
        public string $name,
        public string $status,
        public string $message,
        public string $action,
    ) {}

    public static function ok(string $name, string $message, string $action = 'Keine Handlung erforderlich.'): self
    {
        return new self($name, self::OK, $message, $action);
    }

    public static function warning(string $name, string $message, string $action): self
    {
        return new self($name, self::WARNUNG, $message, $action);
    }

    public static function error(string $name, string $message, string $action): self
    {
        return new self($name, self::FEHLER, $message, $action);
    }

    public function isError(): bool
    {
        return $this->status === self::FEHLER;
    }
}
