<?php

declare(strict_types=1);

namespace App\Application\Install;

/**
 * Ergebnis einer einzelnen Voraussetzungspruefung der Inbetriebnahme.
 */
final readonly class RequirementResult
{
    public function __construct(
        public string $name,
        public bool $fulfilled,
        public string $message,
    ) {}
}
