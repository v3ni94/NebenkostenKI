<?php

declare(strict_types=1);

namespace App\Services\Pdf\View;

/**
 * Optionale Bankverbindung des Vermieters (Abschnitt 2.2).
 *
 * Die Angabe ist freiwillig. Ohne übergebene Bankverbindung erscheint im PDF
 * kein Zahlungsdatenblock; es wird nie eine Bankverbindung ergänzt.
 */
final readonly class BankAccount
{
    public function __construct(
        public string $accountHolder,
        public string $iban,
        public ?string $bic = null,
        public ?string $bankName = null,
        public ?string $paymentReference = null,
    ) {}
}
