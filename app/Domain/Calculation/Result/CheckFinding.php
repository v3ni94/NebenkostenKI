<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Result;

/**
 * Ein Prüfergebnis der Berechnungsengine.
 *
 * Prüfergebnisse sind Rückgabewerte, keine Exceptions. Ein BLOCKER
 * verhindert die Finalisierung, die Berechnung selbst bleibt aber lesbar und
 * erklärbar.
 */
final readonly class CheckFinding
{
    /**
     * @param  array<string, string|int|bool|null>  $context  technische Zusatzangaben für Prüfbericht und Protokoll
     */
    public function __construct(
        public CheckCode $code,
        public CheckSeverity $severity,
        public string $message,
        public array $context = [],
    ) {}

    /**
     * @param  array<string, string|int|bool|null>  $context
     */
    public static function blocker(CheckCode $code, string $message, array $context = []): self
    {
        return new self($code, CheckSeverity::BLOCKER, $message, $context);
    }

    /**
     * @param  array<string, string|int|bool|null>  $context
     */
    public static function warning(CheckCode $code, string $message, array $context = []): self
    {
        return new self($code, CheckSeverity::WARNING, $message, $context);
    }

    /**
     * @param  array<string, string|int|bool|null>  $context
     */
    public static function info(CheckCode $code, string $message, array $context = []): self
    {
        return new self($code, CheckSeverity::INFO, $message, $context);
    }

    /**
     * @param  array<string, string|int|bool|null>  $context
     */
    public static function passed(CheckCode $code, string $message, array $context = []): self
    {
        return new self($code, CheckSeverity::PASSED, $message, $context);
    }

    public function blocksFinalization(): bool
    {
        return $this->severity->blocksFinalization();
    }
}
