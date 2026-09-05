<?php

declare(strict_types=1);

namespace App\Rules\Context;

/**
 * Betriebsumgebung, soweit fuer Pruefregeln erforderlich.
 *
 * Der Malware-Scanner ist eine Betreiberpflicht. Steht er produktiv auf
 * "disabled", erhaelt der Adminbereich einen Hinweis.
 */
final readonly class RuleEnvironment
{
    public function __construct(
        public string $malwareScannerDriver = 'disabled',
        public bool $isProduction = false,
    ) {}

    public static function fromConfig(): self
    {
        $driver = config('smartabrechnen.uploads.malware_scanner.driver', 'disabled');
        $environment = config('app.env', 'production');

        return new self(
            is_string($driver) ? $driver : 'disabled',
            is_string($environment) && $environment === 'production',
        );
    }
}
