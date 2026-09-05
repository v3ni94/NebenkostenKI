<?php

declare(strict_types=1);

namespace App\Rules\Engine;

use App\Enums\ValidationSeverity;

/**
 * Ein einzelner Befund einer Pruefregel.
 *
 * Eine Regel liefert eine Liste von Befunden. Liefert sie eine leere Liste,
 * ergaenzt die Engine ein Ergebnis mit der Severity BESTANDEN, damit der
 * Nutzer sieht, was geprueft wurde.
 */
final readonly class RuleFinding
{
    /**
     * @param  array<string, string|int|bool|null>  $context  technische Zusatzangaben fuer Protokoll und Anzeige
     */
    public function __construct(
        public ValidationSeverity $severity,
        public string $description,
        public ?string $entityType = null,
        public ?string $entityId = null,
        public array $context = [],
    ) {}

    /**
     * @param  array<string, string|int|bool|null>  $context
     */
    public static function blocker(string $description, ?string $entityType = null, ?string $entityId = null, array $context = []): self
    {
        return new self(ValidationSeverity::BLOCKER, $description, $entityType, $entityId, $context);
    }

    /**
     * @param  array<string, string|int|bool|null>  $context
     */
    public static function warnung(string $description, ?string $entityType = null, ?string $entityId = null, array $context = []): self
    {
        return new self(ValidationSeverity::WARNUNG, $description, $entityType, $entityId, $context);
    }

    /**
     * @param  array<string, string|int|bool|null>  $context
     */
    public static function hinweis(string $description, ?string $entityType = null, ?string $entityId = null, array $context = []): self
    {
        return new self(ValidationSeverity::HINWEIS, $description, $entityType, $entityId, $context);
    }
}
