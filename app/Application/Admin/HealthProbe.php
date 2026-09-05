<?php

declare(strict_types=1);

namespace App\Application\Admin;

/**
 * Ergebnis eines technischen Healthchecks.
 *
 * VERBINDLICH: Ein Probe-Ergebnis enthaelt NIEMALS ein Secret, auch nicht
 * teilweise maskiert. Zulaessig sind ausschliesslich
 *
 *   - erreichbar ja, nein oder nicht geprueft
 *   - eine Versionsangabe
 *   - eine Fehlerklasse, also der Ausnahmetyp ohne Meldungstext
 *
 * Insbesondere werden Host, Benutzername, Pfad, Passwort, Schluessel und
 * Endpunkt nicht ausgegeben. Ein Hostname ist zwar kein Geheimnis, gehoert
 * aber nicht in eine Oberflaeche, die auch der Support sieht.
 */
final readonly class HealthProbe
{
    public function __construct(
        public string $name,
        public bool $configured,
        public ?bool $reachable,
        public ?string $version = null,
        public ?string $errorClass = null,
        public string $note = '',
        public ?bool $supported = null,
    ) {}

    public function statusLabel(): string
    {
        if (! $this->configured) {
            return 'Nicht konfiguriert';
        }

        return match ($this->reachable) {
            true => 'Erreichbar',
            false => 'Nicht erreichbar',
            default => 'Nicht geprüft',
        };
    }

    public function variant(): string
    {
        if (! $this->configured) {
            return 'warning';
        }

        return match ($this->reachable) {
            true => $this->supported === false ? 'warning' : 'success',
            false => 'error',
            default => 'info',
        };
    }
}
