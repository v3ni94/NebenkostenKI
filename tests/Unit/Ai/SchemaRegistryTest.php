<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\Schemas\SchemaRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Registry der versionierten Extraktionsschemata (Abschnitt 13.7).
 */
final class SchemaRegistryTest extends TestCase
{
    /**
     * Die Kernschemata aus Abschnitt 13.7 plus das Schema des Abgleichs.
     *
     * @var list<string>
     */
    private const REQUIRED_KEYS = [
        'dokumentklassifikation',
        'rechnung_bescheid',
        'hausgeldabrechnung',
        'grundsteuerbescheid',
        'mietvertrag',
        'vorjahresabrechnung',
        'heizkostenabrechnung',
        'mieter_einheitenliste',
        'zahlungsuebersicht',
        'zaehlerwerte',
        'reconciliation',
    ];

    public function test_alle_kernschemata_sind_registriert(): void
    {
        $registry = new SchemaRegistry;

        foreach (self::REQUIRED_KEYS as $key) {
            self::assertTrue($registry->has($key), sprintf('Schema "%s" fehlt.', $key));
        }
    }

    public function test_jedes_schema_hat_version_und_hash(): void
    {
        $registry = new SchemaRegistry;

        foreach ($registry->keys() as $key) {
            $definition = $registry->get($key);

            self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $definition->version);
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $definition->hash());
            self::assertSame(12, strlen($definition->shortHash()));
        }
    }

    public function test_hash_ist_ueber_mehrere_aufrufe_stabil(): void
    {
        $first = (new SchemaRegistry)->hash('hausgeldabrechnung');
        $second = (new SchemaRegistry)->hash('hausgeldabrechnung');
        $third = (new SchemaRegistry)->get('hausgeldabrechnung')->hash();

        self::assertSame($first, $second);
        self::assertSame($first, $third);
    }

    public function test_hashes_unterscheiden_sich_je_schema(): void
    {
        $registry = new SchemaRegistry;
        $hashes = [];

        foreach ($registry->keys() as $key) {
            $hashes[] = $registry->hash($key);
        }

        self::assertSame(count($hashes), count(array_unique($hashes)));
    }

    public function test_json_schema_ist_streng(): void
    {
        $registry = new SchemaRegistry;

        foreach ($registry->keys() as $key) {
            $schema = $registry->get($key)->jsonSchema();

            self::assertSame('object', $schema['type']);
            self::assertFalse($schema['additionalProperties']);
            self::assertIsArray($schema['properties']);
            self::assertIsArray($schema['required']);
            self::assertSame(
                array_keys($schema['properties']),
                $schema['required'],
                sprintf('Bei Schema "%s" sind nicht alle Schluessel Pflichtschluessel.', $key),
            );
        }
    }

    public function test_jedes_feld_traegt_quellenbezug(): void
    {
        $registry = new SchemaRegistry;

        foreach ($registry->keys() as $key) {
            $this->assertFieldsCarrySource($registry->get($key)->jsonSchema(), $key);
        }
    }

    public function test_betraege_sind_ausschliesslich_integer(): void
    {
        $registry = new SchemaRegistry;

        foreach ($registry->keys() as $key) {
            $this->assertAmountsAreIntegers($registry->get($key)->jsonSchema(), $key);
        }
    }

    public function test_unbekannter_schluessel_wirft_ausnahme(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SchemaRegistry)->get('gibt_es_nicht');
    }

    public function test_fingerprints_enthalten_alle_schemata(): void
    {
        $registry = new SchemaRegistry;
        $fingerprints = $registry->fingerprints();

        self::assertCount(count($registry->keys()), $fingerprints);
        self::assertArrayHasKey('grundsteuerbescheid', $fingerprints);
        self::assertSame('1.0.0', $fingerprints['grundsteuerbescheid']['version']);
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function assertFieldsCarrySource(array $schema, string $schemaKey): void
    {
        $properties = $schema['properties'] ?? null;

        if (! is_array($properties)) {
            return;
        }

        // Ein Feldumschlag ist daran erkennbar, dass er value enthaelt.
        if (array_key_exists('value', $properties)) {
            foreach (['confidence', 'source_page', 'source_excerpt'] as $required) {
                self::assertArrayHasKey($required, $properties, sprintf(
                    'Ein Feld in Schema "%s" traegt keinen vollstaendigen Quellenbezug.',
                    $schemaKey,
                ));
            }

            return;
        }

        foreach ($properties as $property) {
            if (! is_array($property)) {
                continue;
            }

            if (($property['type'] ?? null) === 'array' && is_array($property['items'] ?? null)) {
                /** @var array<string, mixed> $items */
                $items = $property['items'];
                $this->assertFieldsCarrySource($items, $schemaKey);

                continue;
            }

            /** @var array<string, mixed> $property */
            $this->assertFieldsCarrySource($property, $schemaKey);
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function assertAmountsAreIntegers(array $schema, string $schemaKey, string $path = ''): void
    {
        $properties = $schema['properties'] ?? null;

        if (! is_array($properties)) {
            return;
        }

        foreach ($properties as $name => $property) {
            if (! is_array($property)) {
                continue;
            }

            $childPath = $path === '' ? (string) $name : $path.'.'.$name;

            if (str_ends_with((string) $name, '_cent') && is_array($property['properties']['value'] ?? null)) {
                self::assertSame(
                    ['integer', 'null'],
                    $property['properties']['value']['type'],
                    sprintf('Betragsfeld "%s" in Schema "%s" ist nicht als Integer definiert.', $childPath, $schemaKey),
                );
            }

            if (($property['type'] ?? null) === 'array' && is_array($property['items'] ?? null)) {
                /** @var array<string, mixed> $items */
                $items = $property['items'];
                $this->assertAmountsAreIntegers($items, $schemaKey, $childPath.'[]');

                continue;
            }

            /** @var array<string, mixed> $property */
            $this->assertAmountsAreIntegers($property, $schemaKey, $childPath);
        }
    }
}
