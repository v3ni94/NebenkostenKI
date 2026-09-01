<?php

declare(strict_types=1);

namespace App\Rules\Context;

use App\Domain\Money\Money;

/**
 * Vergleichsbasis fuer neue Lieferanten und ungewoehnlich hohe Einzelbetraege.
 *
 * Die bekannten Lieferanten stammen aus dem Vorjahreslauf. Die Betragsgrenze
 * ist eine Aufmerksamkeitsschwelle, keine fachliche Bewertung.
 */
final readonly class RuleSupplierHistory
{
    /**
     * @param  list<string>  $knownSuppliers
     */
    public function __construct(
        public array $knownSuppliers = [],
        public ?Money $singleAmountAttentionThreshold = null,
        public bool $previousRunAvailable = false,
    ) {}

    public function isKnown(string $supplier): bool
    {
        foreach ($this->knownSuppliers as $known) {
            if (mb_strtolower(trim($known)) === mb_strtolower(trim($supplier))) {
                return true;
            }
        }

        return false;
    }
}
