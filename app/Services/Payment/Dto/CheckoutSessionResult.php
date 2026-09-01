<?php

declare(strict_types=1);

namespace App\Services\Payment\Dto;

/**
 * Ergebnis einer angelegten gehosteten Zahlungsseite.
 *
 * Die Weiterleitungsadresse fuehrt auf die Seite des Anbieters. Sie ist KEIN
 * Zahlungsnachweis; freigeschaltet wird ausschliesslich ueber den
 * signaturgeprueften Webhook (Abschnitt 15.1).
 */
final readonly class CheckoutSessionResult
{
    public function __construct(
        public string $sessionId,
        public string $redirectUrl,
        public int $amountTotalCent,
        public string $currency,
        public ?string $paymentIntentId = null,
    ) {}
}
