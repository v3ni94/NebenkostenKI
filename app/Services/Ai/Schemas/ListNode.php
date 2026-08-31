<?php

declare(strict_types=1);

namespace App\Services\Ai\Schemas;

/**
 * Listenknoten eines Extraktionsschemas, zum Beispiel Kostenpositionen oder
 * Einheiten.
 *
 * maxItems ist absichtlich nicht Teil des an den Provider gesendeten JSON
 * Schemas, weil die strikten Structured-Output-Modi der Provider
 * Laengenbegrenzungen auf Arrays nicht durchgaengig unterstuetzen. Die
 * Begrenzung wird ausschliesslich serverseitig durchgesetzt und dient als
 * Schutz vor uebergrossen Antworten.
 */
final class ListNode implements SchemaNode
{
    public function __construct(
        public readonly SchemaNode $item,
        public readonly string $description = '',
        public readonly int $maxItems = 400,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toJsonSchema(): array
    {
        $schema = [
            'type' => 'array',
            'items' => $this->item->toJsonSchema(),
        ];

        if ($this->description !== '') {
            $schema['description'] = $this->description;
        }

        return $schema;
    }
}
