<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

/**
 * Eine einzelne Schemaverletzung.
 *
 * VERBINDLICH: Die Verletzung fuehrt Schemapfad, Code und den erwarteten
 * sowie den tatsaechlich gelieferten TYP mit. Der beanstandete WERT wird
 * niemals mitgefuehrt, weil er Dokumentinhalt ist und in Protokolle,
 * Ausnahmen und Reparaturprompts gelangen wuerde.
 */
final class SchemaViolation
{
    public function __construct(
        public readonly string $path,
        public readonly SchemaViolationCode $code,
        public readonly ?string $expectedType = null,
        public readonly ?string $actualType = null,
    ) {}

    /**
     * Zeile fuer den Reparaturprompt und das Protokoll.
     */
    public function describe(): string
    {
        $description = sprintf('%s: %s', $this->path === '' ? '(Wurzel)' : $this->path, $this->code->repairInstruction());

        if ($this->expectedType !== null && $this->actualType !== null) {
            $description .= sprintf(' Erwartet: %s. Geliefert: %s.', $this->expectedType, $this->actualType);
        }

        return $description;
    }

    /**
     * Nur Metadaten fuer Protokolle.
     *
     * @return array<string, string|null>
     */
    public function toLogContext(): array
    {
        return [
            'path' => $this->path,
            'code' => $this->code->value,
            'expected_type' => $this->expectedType,
            'actual_type' => $this->actualType,
        ];
    }
}
