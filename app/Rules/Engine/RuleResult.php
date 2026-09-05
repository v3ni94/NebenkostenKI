<?php

declare(strict_types=1);

namespace App\Rules\Engine;

use App\Enums\ValidationSeverity;

/**
 * Ein Ergebnis der Regel-Engine, angereichert um die Regelmetadaten.
 *
 * Das Ergebnis ist als App\Models\ValidationIssue persistierbar. Die Engine
 * selbst schreibt nicht in die Datenbank; das uebernimmt
 * App\Rules\Engine\ValidationIssueWriter.
 */
final readonly class RuleResult
{
    /**
     * @param  array<string, string|int|bool|null>  $context
     */
    public function __construct(
        public string $ruleCode,
        public string $ruleVersion,
        public ValidationSeverity $severity,
        public string $title,
        public string $description,
        public string $reference,
        public bool $userResolvable,
        public ?string $entityType = null,
        public ?string $entityId = null,
        public array $context = [],
    ) {}

    public static function fromFinding(Rule $rule, RuleFinding $finding): self
    {
        return new self(
            $rule->code(),
            $rule->version(),
            $finding->severity,
            $rule->title(),
            $finding->description,
            $rule->reference(),
            $rule->isUserResolvable(),
            $finding->entityType,
            $finding->entityId,
            $finding->context,
        );
    }

    /**
     * Ergebnis eines bestandenen Pruefschritts.
     */
    public static function passed(Rule $rule): self
    {
        return new self(
            $rule->code(),
            $rule->version(),
            ValidationSeverity::BESTANDEN,
            $rule->title(),
            $rule->passedDescription(),
            $rule->reference(),
            false,
        );
    }

    public function blocksFinalization(): bool
    {
        return $this->severity->blocksFinalization();
    }

    /**
     * Abbildung auf die Spalten von validation_issues.
     *
     * @return array<string, string|bool|null>
     */
    public function toIssueAttributes(): array
    {
        return [
            'rule_code' => $this->ruleCode,
            'rule_version' => $this->ruleVersion,
            'severity' => $this->severity->value,
            'blocks_finalization' => $this->blocksFinalization(),
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'title' => $this->title,
            'description' => $this->description,
            'legal_reference' => $this->reference,
        ];
    }
}
