<?php

declare(strict_types=1);

namespace App\Services\Ai\Schemas;

/**
 * Lieferant eines versionierten Extraktionsschemas.
 *
 * Je Schema genau eine Klasse, damit eine Versionsanhebung einen
 * nachvollziehbaren Commit in einer klar zugeordneten Datei erzeugt.
 */
interface SchemaProviderInterface
{
    /**
     * Technischer Schemaschluessel, zum Beispiel "hausgeldabrechnung".
     */
    public static function key(): string;

    /**
     * Semantische Version des Schemas.
     */
    public static function version(): string;

    public static function definition(): SchemaDefinition;
}
