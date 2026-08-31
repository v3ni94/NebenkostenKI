<?php

declare(strict_types=1);

namespace App\Services\Ai\Schemas;

use InvalidArgumentException;

/**
 * Registry aller versionierten Extraktionsschemata nach Abschnitt 13.7.
 *
 * Liefert je Schluessel das Schema, seine Version und seinen Hash. Version
 * und Hash werden zu jedem KI-Aufruf und zu jedem extrahierten Feld
 * protokolliert (Abschnitt 6.4), damit ein bezahlter Berechnungsstand
 * reproduzierbar bleibt.
 */
final class SchemaRegistry
{
    /**
     * Schemaklassen je Schluessel.
     *
     * @var array<string, class-string<SchemaProviderInterface>>
     */
    private const PROVIDERS = [
        'dokumentklassifikation' => DocumentClassificationSchema::class,
        'rechnung_bescheid' => InvoiceOrAssessmentSchema::class,
        'hausgeldabrechnung' => CondominiumStatementSchema::class,
        'grundsteuerbescheid' => PropertyTaxAssessmentSchema::class,
        'mietvertrag' => LeaseContractSchema::class,
        'vorjahresabrechnung' => PriorYearStatementSchema::class,
        'heizkostenabrechnung' => HeatingStatementSchema::class,
        'mieter_einheitenliste' => TenantUnitListSchema::class,
        'zahlungsuebersicht' => PaymentOverviewSchema::class,
        'zaehlerwerte' => MeterReadingsSchema::class,
        'reconciliation' => ReconciliationSchema::class,
    ];

    /** @var array<string, SchemaDefinition> */
    private array $cache = [];

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys(self::PROVIDERS);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, self::PROVIDERS);
    }

    public function get(string $key): SchemaDefinition
    {
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        if (! $this->has($key)) {
            throw new InvalidArgumentException(sprintf('Unbekannter Schemaschluessel "%s".', $key));
        }

        $providerClass = self::PROVIDERS[$key];

        return $this->cache[$key] = $providerClass::definition();
    }

    public function version(string $key): string
    {
        return $this->get($key)->version;
    }

    public function hash(string $key): string
    {
        return $this->get($key)->hash();
    }

    /**
     * Version und Hash aller Schemata, fuer den Adminbereich und
     * Migrationspruefungen.
     *
     * @return array<string, array{version: string, hash: string}>
     */
    public function fingerprints(): array
    {
        $fingerprints = [];

        foreach ($this->keys() as $key) {
            $definition = $this->get($key);
            $fingerprints[$key] = [
                'version' => $definition->version,
                'hash' => $definition->hash(),
            ];
        }

        return $fingerprints;
    }
}
