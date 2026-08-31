<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

/**
 * Ergebnis einer Schemavalidierung.
 *
 * Bei Erfolg enthaelt data() die freigegebenen strukturierten Felder und
 * fields() die flache Feldliste mit Quellenbezug. Bei Verletzungen enthaelt
 * violations() ausschliesslich Pfade, Codes und Typen, niemals die
 * beanstandeten Werte.
 */
final class ValidationOutcome
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, ExtractedValue>  $fields
     * @param  list<SchemaViolation>  $violations
     */
    private function __construct(
        private readonly array $data,
        private readonly array $fields,
        private readonly array $violations,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, ExtractedValue>  $fields
     */
    public static function valid(array $data, array $fields): self
    {
        return new self($data, $fields, []);
    }

    /**
     * @param  list<SchemaViolation>  $violations
     */
    public static function invalid(array $violations): self
    {
        return new self([], [], $violations);
    }

    public function isValid(): bool
    {
        return $this->violations === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * @return array<string, ExtractedValue>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * @return list<SchemaViolation>
     */
    public function violations(): array
    {
        return $this->violations;
    }

    /**
     * @return list<string>
     */
    public function violationCodes(): array
    {
        return array_values(array_unique(array_map(
            static fn (SchemaViolation $violation): string => $violation->code->value,
            $this->violations,
        )));
    }

    /**
     * @return list<string>
     */
    public function violationPaths(): array
    {
        return array_values(array_unique(array_map(
            static fn (SchemaViolation $violation): string => $violation->path,
            $this->violations,
        )));
    }

    /**
     * Praezise Fehlermeldung fuer den kontrollierten Reparaturversuch.
     *
     * Enthaelt ausschliesslich Schemapfade, Codes und Typangaben. Der
     * beanstandete Wert und jeder Dokumentinhalt bleiben aussen vor.
     */
    public function repairInstruction(int $maxLines = 25): string
    {
        $lines = [];
        $violations = array_slice($this->violations, 0, $maxLines);

        foreach ($violations as $violation) {
            $lines[] = '- '.$violation->describe();
        }

        $omitted = count($this->violations) - count($violations);

        if ($omitted > 0) {
            $lines[] = sprintf('- Weitere %d Verletzungen wurden nicht aufgefuehrt.', $omitted);
        }

        return implode("\n", $lines);
    }
}
