<?php

declare(strict_types=1);

namespace App\Services\Ai\Schemas;

/**
 * Knoten eines versionierten Extraktionsschemas.
 *
 * Der Knotenbaum ist die einzige Quelle sowohl fuer das an den Provider
 * gesendete JSON Schema als auch fuer die serverseitige Validierung. Damit
 * kann eine Schemaaenderung nicht versehentlich nur eine der beiden Seiten
 * treffen.
 */
interface SchemaNode
{
    /**
     * Reines JSON Schema ohne herstellerspezifische Zusatzschluessel.
     *
     * @return array<string, mixed>
     */
    public function toJsonSchema(): array;
}
