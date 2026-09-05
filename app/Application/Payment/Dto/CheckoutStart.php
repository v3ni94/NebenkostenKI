<?php

declare(strict_types=1);

namespace App\Application\Payment\Dto;

use App\Models\Payment;

/**
 * Ergebnis eines eingeleiteten Zahlungsvorgangs.
 *
 * Die Weiterleitungsadresse fuehrt auf die gehostete Zahlungsseite des
 * Anbieters. Sie ist kein Zahlungsnachweis (Abschnitt 15.1).
 */
final readonly class CheckoutStart
{
    public function __construct(
        public Payment $payment,
        public PriceQuote $quote,
        public string $redirectUrl,
    ) {}
}
