<?php

declare(strict_types=1);

namespace App\Services\Pdf\Store;

/**
 * Zuordnung eines erzeugten Dokuments zu Mandant, Lauf, Mieterabrechnung und
 * Rechnung. Die Mandantentrennung steckt bereits im Ablagepfad.
 */
final readonly class DocumentOwnership
{
    public function __construct(
        public string $organizationId,
        public ?string $billingRunId = null,
        public ?string $unitStatementId = null,
        public ?string $invoiceId = null,
    ) {}
}
