<?php

declare(strict_types=1);

namespace App\Services\Ai\Schemas;

/**
 * Ein einzelnes extrahiertes Feld mit verbindlichem Quellenbezug.
 *
 * Grundsatz 2 der Spezifikation: Kein erkannter Wert darf ohne Quellenbezug
 * gespeichert werden. Jedes Feld tragt daher zwingend:
 *
 * - value: der erkannte Wert oder null, wenn er nicht im Dokument steht
 * - confidence: Konfidenz zwischen 0 und 1
 * - source_page: Seitenzahl im Quelldokument
 * - source_excerpt: kurzer Fundstellenausschnitt, laengenbegrenzt
 * - bounding_box: optionale Koordinaten der Fundstelle
 *
 * Alle Schluessel sind Pflichtschluessel. Fehlende Angaben werden als null
 * ausgegeben und niemals geschaetzt (Grundsatz 5).
 */
final class FieldNode implements SchemaNode
{
    /**
     * Maximale Laenge eines Fundstellenausschnitts in Zeichen.
     *
     * Begruendung: Zulaessig sind nur die fuer ein konkretes Feld
     * erforderlichen kurzen Ausschnitte (Grundsatz 4). Ein vollstaendiger
     * Textabsatz waere ein OCR-Teiltext und damit nicht speicherbar.
     */
    public const MAX_SOURCE_EXCERPT_LENGTH = 240;

    /**
     * @param  list<string>|null  $enumValues
     */
    private function __construct(
        public readonly ValueKind $kind,
        public readonly string $description,
        public readonly bool $nullable = true,
        public readonly ?int $maxLength = null,
        public readonly ?array $enumValues = null,
        public readonly bool $boundingBoxAllowed = false,
    ) {}

    public static function amountCent(string $description, bool $boundingBox = false): self
    {
        return new self(ValueKind::AMOUNT_CENT, $description, boundingBoxAllowed: $boundingBox);
    }

    public static function isoDate(string $description): self
    {
        return new self(ValueKind::ISO_DATE, $description);
    }

    public static function text(string $description, int $maxLength = 200): self
    {
        return new self(ValueKind::TEXT, $description, maxLength: $maxLength);
    }

    public static function decimal(string $description): self
    {
        return new self(ValueKind::DECIMAL_STRING, $description);
    }

    public static function integer(string $description): self
    {
        return new self(ValueKind::INTEGER, $description);
    }

    public static function boolean(string $description): self
    {
        return new self(ValueKind::BOOLEAN, $description);
    }

    /**
     * @param  list<string>  $values
     */
    public static function enumOf(string $description, array $values): self
    {
        return new self(ValueKind::ENUM, $description, enumValues: $values);
    }

    /**
     * @return array<string, mixed>
     */
    public function toJsonSchema(): array
    {
        $properties = [
            'value' => $this->valueJsonSchema(),
            'confidence' => [
                'type' => 'number',
                'description' => 'Konfidenz zwischen 0 und 1.',
            ],
            'source_page' => [
                'type' => ['integer', 'null'],
                'description' => 'Seitenzahl der Fundstelle, beginnend bei 1.',
            ],
            'source_excerpt' => [
                'type' => ['string', 'null'],
                'description' => sprintf(
                    'Kurzer Fundstellenausschnitt, maximal %d Zeichen.',
                    self::MAX_SOURCE_EXCERPT_LENGTH,
                ),
            ],
        ];

        if ($this->boundingBoxAllowed) {
            $properties['bounding_box'] = self::boundingBoxJsonSchema();
        }

        return [
            'type' => 'object',
            'description' => $this->description,
            'additionalProperties' => false,
            'required' => array_keys($properties),
            'properties' => $properties,
        ];
    }

    /**
     * Pflichtschluessel des Feldumschlags.
     *
     * @return list<string>
     */
    public function envelopeKeys(): array
    {
        $keys = ['value', 'confidence', 'source_page', 'source_excerpt'];

        if ($this->boundingBoxAllowed) {
            $keys[] = 'bounding_box';
        }

        return $keys;
    }

    /**
     * @return array<string, mixed>
     */
    private function valueJsonSchema(): array
    {
        $schema = match ($this->kind) {
            ValueKind::AMOUNT_CENT => [
                'type' => ['integer', 'null'],
                'description' => 'Betrag ausschliesslich als Integer in Cent.',
            ],
            ValueKind::INTEGER => ['type' => ['integer', 'null']],
            ValueKind::BOOLEAN => ['type' => ['boolean', 'null']],
            ValueKind::ISO_DATE => [
                'type' => ['string', 'null'],
                'description' => 'Datum im Format JJJJ-MM-TT.',
            ],
            ValueKind::DECIMAL_STRING => [
                'type' => ['string', 'null'],
                'description' => 'Dezimalwert als Zeichenkette mit Punkt als Dezimaltrenner.',
            ],
            ValueKind::ENUM => [
                'type' => ['string', 'null'],
                'enum' => [...($this->enumValues ?? []), null],
            ],
            ValueKind::TEXT => ['type' => ['string', 'null']],
        };

        if (! $this->nullable) {
            $schema['type'] = is_array($schema['type']) ? $schema['type'][0] : $schema['type'];
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private static function boundingBoxJsonSchema(): array
    {
        return [
            'type' => ['object', 'null'],
            'description' => 'Optionale Koordinaten der Fundstelle, relativ zur Seite.',
            'additionalProperties' => false,
            'required' => ['page', 'x', 'y', 'width', 'height'],
            'properties' => [
                'page' => ['type' => 'integer'],
                'x' => ['type' => 'number'],
                'y' => ['type' => 'number'],
                'width' => ['type' => 'number'],
                'height' => ['type' => 'number'],
            ],
        ];
    }
}
