<?php

declare(strict_types=1);

namespace App\Services\Ai\Schemas;

/**
 * Ein versioniertes Extraktionsschema mit stabilem Hash.
 *
 * Version und Hash werden zu jedem extrahierten Feld protokolliert, damit
 * eine spaetere Abrechnung reproduzierbar bleibt und eine Schemaaenderung
 * nachvollziehbar ist. Der Hash wird deterministisch aus dem serialisierten
 * JSON Schema gebildet, weil PHP-Arrays ihre Einfuegereihenfolge behalten.
 */
final class SchemaDefinition
{
    private ?string $hash = null;

    /** @var array<string, mixed>|null */
    private ?array $jsonSchema = null;

    public function __construct(
        public readonly string $key,
        public readonly string $version,
        private readonly ObjectNode $root,
    ) {}

    public function root(): ObjectNode
    {
        return $this->root;
    }

    /**
     * Reines JSON Schema fuer den Providerrequest.
     *
     * @return array<string, mixed>
     */
    public function jsonSchema(): array
    {
        return $this->jsonSchema ??= $this->root->toJsonSchema();
    }

    /**
     * Stabiler SHA-256-Hash ueber Schluessel, Version und Schemastruktur.
     */
    public function hash(): string
    {
        if ($this->hash !== null) {
            return $this->hash;
        }

        $canonical = json_encode(
            [
                'key' => $this->key,
                'version' => $this->version,
                'schema' => $this->jsonSchema(),
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return $this->hash = hash('sha256', $canonical);
    }

    /**
     * Gekuerzter Hash fuer Protokolle und Oberflaeche.
     */
    public function shortHash(): string
    {
        return substr($this->hash(), 0, 12);
    }

    /**
     * Bezeichner fuer die Structured-Output-Konfiguration der Provider.
     * Nur Kleinbuchstaben, Ziffern und Unterstriche.
     */
    public function providerSchemaName(): string
    {
        return $this->key;
    }
}
