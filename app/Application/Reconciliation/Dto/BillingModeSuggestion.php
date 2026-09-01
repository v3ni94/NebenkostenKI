<?php

declare(strict_types=1);

namespace App\Application\Reconciliation\Dto;

use App\Enums\BillingMode;

/**
 * Vorschlag des Abrechnungswegs nach Abschnitt 5.3.
 *
 * Der Vorschlag ist unverbindlich. Der Nutzer kann jederzeit wechseln. Ein
 * Wechsel loescht keine strukturierten Extraktionsdaten.
 */
final readonly class BillingModeSuggestion
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public BillingMode $suggested,
        public BillingMode $current,
        public array $reasons = [],
        public bool $confident = false,
    ) {}

    public function differsFromCurrent(): bool
    {
        return $this->suggested !== $this->current;
    }
}
