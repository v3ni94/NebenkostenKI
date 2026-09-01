<?php

declare(strict_types=1);

namespace App\Services\Pdf\View;

use App\Domain\Money\Money;

/**
 * Rechnungsposition der HVM-Rechnung (Abschnitt 15.2).
 *
 * Menge, Einzelpreis und Nettobetrag werden übergeben und unverändert
 * gedruckt. Die Rechnung rechnet nicht selbst.
 */
final readonly class InvoiceLine
{
    public function __construct(
        public string $description,
        public int $quantity,
        public Money $unitPriceNet,
        public Money $totalNet,
        public string $unitLabel = 'Mieterabrechnung',
    ) {}
}
