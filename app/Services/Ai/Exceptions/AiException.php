<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

use RuntimeException;

/**
 * Basisklasse aller Ausnahmen der KI-Schicht.
 *
 * VERBINDLICHE DATENSCHUTZREGEL (Spezifikation Abschnitt 6.4, 13.5 und
 * Grundsatz 4): Keine Ausnahme dieser Schicht darf Dokumentinhalte, rohe
 * Prompts, rohe Modellantworten, Base64-Daten oder Fundstellentexte
 * mitfuehren. Zulaessig sind ausschliesslich technische Metadaten, also
 * Provider, Modell, Zweck, HTTP-Statuscode, Fehlercode, Schemapfade,
 * Verletzungscodes, Tokenzahlen, Dauer und Korrelations-ID.
 *
 * Grund: Ausnahmen landen in Application Logs, im Error Monitoring und in
 * Queue-Payloads. Alle drei Ziele sind fuer Dokumentinhalte gesperrt.
 */
abstract class AiException extends RuntimeException
{
    /**
     * Nur freigegebene technische Metadaten. Niemals Inhalte.
     *
     * @var array<string, scalar|null>
     */
    private array $metadata = [];

    /**
     * @param  array<string, scalar|null>  $metadata
     */
    final public function withMetadata(array $metadata): static
    {
        $clone = clone $this;
        $clone->metadata = $metadata;

        return $clone;
    }

    /**
     * @return array<string, scalar|null>
     */
    final public function metadata(): array
    {
        return $this->metadata;
    }
}
