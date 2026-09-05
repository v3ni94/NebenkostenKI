<?php

declare(strict_types=1);

namespace App\Application\Privacy\Dto;

/**
 * Ergebnis der Prüfung eines Backup-Manifests gegen die Ausschlussliste.
 *
 * Ein Treffer ist kein Hinweis, sondern ein Befund: Das Backup enthält einen
 * Pfad, der nach Abschnitt 19 in keinem Backup liegen darf.
 */
final class ManifestAuditReport
{
    /**
     * @param  list<array{path: string, rule: string}>  $violations
     */
    public function __construct(
        public readonly int $inspectedPaths,
        public readonly array $violations,
    ) {}

    public function isCompliant(): bool
    {
        return $this->violations === [];
    }

    public function summary(): string
    {
        if ($this->isCompliant()) {
            return sprintf(
                'Manifestprüfung bestanden: %d Pfade geprüft, kein verbotener Pfad enthalten.',
                $this->inspectedPaths,
            );
        }

        return sprintf(
            'Manifestprüfung fehlgeschlagen: %d Pfade geprüft, %d verbotene Pfade enthalten.',
            $this->inspectedPaths,
            count($this->violations),
        );
    }
}
