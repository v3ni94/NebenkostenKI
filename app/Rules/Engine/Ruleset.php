<?php

declare(strict_types=1);

namespace App\Rules\Engine;

use App\Rules\Context\RuleContext;
use DateTimeImmutable;

/**
 * Ein Regelstand: die zu einem Stichtag gueltigen Regeln mit Version.
 *
 * Der Regelstand wird im Calculation Snapshot als ruleset_version
 * gespeichert. Ein bezahlter Berechnungsstand kann damit spaeter mit genau
 * derselben Regelzusammensetzung erneut geprueft werden.
 */
final readonly class Ruleset
{
    /**
     * @param  list<Rule>  $rules
     */
    public function __construct(
        public string $version,
        public DateTimeImmutable $referenceDate,
        public array $rules,
    ) {}

    /**
     * Regelstand fuer einen Stichtag, in der Regel der Beginn des
     * Abrechnungszeitraums.
     */
    public static function forDate(DateTimeImmutable $date): self
    {
        return self::fromGeneration(RulesetCatalog::generationFor($date));
    }

    /**
     * Regelstand eines Abrechnungslaufs.
     */
    public static function forContext(RuleContext $context): self
    {
        return self::forDate($context->billingPeriod->start);
    }

    /**
     * Regelstand aus einer gespeicherten Version, fuer die Reproduktion eines
     * bezahlten Berechnungsstands.
     */
    public static function fromVersion(string $version): self
    {
        return self::fromGeneration(RulesetCatalog::generationByVersion($version));
    }

    /**
     * @return list<string>
     */
    public function ruleCodes(): array
    {
        return array_map(static fn (Rule $rule): string => $rule->code(), $this->rules);
    }

    public function has(string $code): bool
    {
        return in_array($code, $this->ruleCodes(), true);
    }

    public function count(): int
    {
        return count($this->rules);
    }

    private static function fromGeneration(RulesetGeneration $generation): self
    {
        return new self(
            $generation->version,
            $generation->referenceDate(),
            RuleRegistry::effectiveOn($generation->referenceDate())
        );
    }
}
