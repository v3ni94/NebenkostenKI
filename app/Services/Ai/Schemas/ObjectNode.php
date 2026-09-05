<?php

declare(strict_types=1);

namespace App\Services\Ai\Schemas;

/**
 * Objektknoten eines Extraktionsschemas.
 *
 * Alle Schluessel sind Pflichtschluessel und additionalProperties ist immer
 * false. Das ist die Voraussetzung fuer die strikten
 * Structured-Output-Modi beider Provider und verhindert, dass ein Modell
 * zusaetzliche, nicht freigegebene Felder liefert.
 *
 * Nicht vorhandene Angaben werden ueber value = null ausgedrueckt, nicht
 * durch Weglassen des Schluessels (Grundsatz 5).
 */
final class ObjectNode implements SchemaNode
{
    /** @var array<string, SchemaNode> */
    private array $children = [];

    public function __construct(
        private readonly string $description = '',
    ) {}

    public static function make(string $description = ''): self
    {
        return new self($description);
    }

    public function field(string $name, FieldNode $field): self
    {
        $this->children[$name] = $field;

        return $this;
    }

    public function object(string $name, self $node): self
    {
        $this->children[$name] = $node;

        return $this;
    }

    public function listOf(string $name, SchemaNode $item, string $description = '', int $maxItems = 400): self
    {
        $this->children[$name] = new ListNode($item, $description, $maxItems);

        return $this;
    }

    /**
     * @return array<string, SchemaNode>
     */
    public function children(): array
    {
        return $this->children;
    }

    /**
     * @return array<string, mixed>
     */
    public function toJsonSchema(): array
    {
        $properties = [];

        foreach ($this->children as $name => $child) {
            $properties[$name] = $child->toJsonSchema();
        }

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => array_keys($properties),
            'properties' => $properties,
        ];

        if ($this->description !== '') {
            $schema['description'] = $this->description;
        }

        return $schema;
    }
}
