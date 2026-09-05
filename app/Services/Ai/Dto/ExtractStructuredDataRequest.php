<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

use App\Enums\DocumentType;

/**
 * Anfrage zur strukturierten Extraktion gegen ein versioniertes Schema.
 *
 * Der Schemaschluessel bestimmt Schema, Promptzusaetze und Modellwahl. Fuer
 * komplexe Tabellen und Vertraege wird das leistungsfaehigere Modell
 * verwendet (Abschnitt 13.8).
 */
final class ExtractStructuredDataRequest
{
    public function __construct(
        public readonly DocumentPayload $document,
        public readonly string $schemaKey,
        public readonly AiRequestContext $context,
        public readonly ?DocumentType $documentType = null,
        /**
         * Neutrale Bezeichnung des Abrechnungszeitraums des Laufs, damit das
         * Modell Zeitraeume ausserhalb des Laufs kennzeichnen kann. Kein
         * Dokumentinhalt.
         */
        public readonly ?string $expectedPeriodFrom = null,
        public readonly ?string $expectedPeriodTo = null,
    ) {}
}
