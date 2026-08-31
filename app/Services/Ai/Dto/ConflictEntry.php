<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

/**
 * Ein einzelner Widerspruch zwischen zwei Providern im Dual-Review-Modus.
 *
 * Fachlich widersprechende Ergebnisse werden nicht durch Mehrheitsentscheid
 * aufgeloest (Abschnitt 13.5). Der Widerspruch wird dem Nutzer gezeigt.
 *
 * Die beiden Werte sind strukturierte Extraktionsdaten und damit zulaessiger
 * Inhalt eines Ergebnis-DTOs. Sie gehoeren jedoch nicht in Logs. Fuer
 * Protokolle steht toLogContext() bereit, das nur den Pfad ausgibt.
 */
final class ConflictEntry
{
    public function __construct(
        public readonly string $path,
        public readonly string $providerKeyA,
        public readonly string|int|float|bool|null $valueA,
        public readonly float $confidenceA,
        public readonly string $providerKeyB,
        public readonly string|int|float|bool|null $valueB,
        public readonly float $confidenceB,
    ) {}

    /**
     * @return array<string, scalar|null>
     */
    public function toLogContext(): array
    {
        return [
            'path' => $this->path,
            'provider_a' => $this->providerKeyA,
            'provider_b' => $this->providerKeyB,
        ];
    }
}
