<?php

declare(strict_types=1);

namespace App\Application\Payment\Dto;

/**
 * Ergebnis der Preisregel fuer eine Korrektur nach Zahlung (Abschnitt 11.5).
 *
 * Ist die Korrektur kostenfrei, ist quote null. Andernfalls enthaelt quote den
 * serverseitig berechneten Betrag, der dem Nutzer VOR der Korrektur
 * transparent angezeigt und von ihm ausdruecklich bestaetigt werden muss.
 */
final readonly class CorrectionCharge
{
    public function __construct(
        public bool $freeOfCharge,
        public ?PriceQuote $quote,
        public string $notice,
        public int $freeDays,
    ) {}

    /**
     * Eine Korrektur mit Betrag verlangt eine ausdrueckliche Bestaetigung. Ohne
     * sie wird kein Betrag erhoben (Abschnitt 11.5).
     */
    public function requiresConfirmation(): bool
    {
        return ! $this->freeOfCharge;
    }
}
