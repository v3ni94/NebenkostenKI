<?php

declare(strict_types=1);

namespace App\Services\Pdf\View;

/**
 * Objekt- und Einheitsangaben des Informationsblocks (Abschnitt 14.1).
 */
final readonly class StatementSubject
{
    public function __construct(
        public string $propertyLabel,
        public ?string $propertyAddressLine = null,
        public ?string $unitLabel = null,
        public ?string $unitPosition = null,
    ) {}
}
