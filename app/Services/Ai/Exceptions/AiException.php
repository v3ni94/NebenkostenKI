<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

use App\Services\Ai\Dto\AiCallMetadata;
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

    /**
     * Metadaten vorangegangener Provideraufrufe desselben Vorgangs, deren
     * Ergebnis verworfen wurde, bevor der Folgeaufruf mit dieser Ausnahme
     * endete (Schema-Fallback). Jeder dieser Aufrufe hat das Dokument
     * uebertragen und Tokens verbraucht; die Application-Schicht weist ihn
     * auch im Fehlerpfad in ai_calls nach.
     *
     * @var list<AiCallMetadata>
     */
    private array $precedingCalls = [];

    final public function withPrecedingCall(AiCallMetadata $metadata): static
    {
        // Kein clone: Ausnahmen sind in PHP nicht klonbar. Die Ausnahme wird
        // ergaenzt und erneut geworfen.
        $this->precedingCalls = [...$this->precedingCalls, $metadata];

        return $this;
    }

    /**
     * @return list<AiCallMetadata>
     */
    final public function precedingCalls(): array
    {
        return $this->precedingCalls;
    }
}
