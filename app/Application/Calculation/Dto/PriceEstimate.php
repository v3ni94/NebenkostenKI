<?php

declare(strict_types=1);

namespace App\Application\Calculation\Dto;

use App\Domain\Money\Money;

/**
 * Unverbindliche Preisschätzung vor der Vorschau (Masterprompt 1.3).
 *
 * VERBINDLICH: Diese Schätzung ist ausdrücklich unverbindlich. Der genaue
 * Endpreis wird vor dem Checkout anhand der tatsächlich erzeugten
 * Mieterabrechnungen erneut serverseitig berechnet; das ist Aufgabe des
 * Zahlungspakets. Verbrauchern werden Preise immer inklusive Umsatzsteuer
 * angezeigt.
 */
final readonly class PriceEstimate
{
    public const string HINT = 'Unverbindliche Schätzung. Der genaue Preis wird vor der Zahlung anhand der '
        .'tatsächlich erzeugten Mieterabrechnungen berechnet.';

    public function __construct(
        public int $statementCount,
        public Money $perStatementGross,
        public Money $baseGross,
        public Money $totalGross,
    ) {}

    public function hint(): string
    {
        return self::HINT;
    }

    /**
     * Rechenweg als Text, damit der Nutzer die Schätzung nachvollziehen kann.
     */
    public function explanation(): string
    {
        return sprintf(
            '%d Mieterabrechnungen × %s zuzüglich Grundpreis %s ergeben voraussichtlich %s brutto.',
            $this->statementCount,
            $this->perStatementGross->format(),
            $this->baseGross->format(),
            $this->totalGross->format()
        );
    }
}
