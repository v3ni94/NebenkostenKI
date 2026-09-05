<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

use App\Enums\DocumentType;

/**
 * Eine bereits extrahierte Quelle fuer den Dokumentabgleich.
 *
 * Der Abgleich arbeitet ausschliesslich mit bereits validierten
 * strukturierten Extraktionsdaten. Es werden keine Originaldateien erneut an
 * einen Provider gesendet. Damit bleibt der Abgleich datenminimal und
 * kostengunstig, und die Originaldateien sind zu diesem Zeitpunkt bereits
 * geloescht (Abschnitt 6.3 Schritt 15).
 */
final class ReconciliationSubject
{
    /**
     * @param  array<string, mixed>  $structuredData  Validierte Extraktionsdaten dieser Quelle.
     */
    public function __construct(
        public readonly string $neutralLabel,
        public readonly DocumentType $documentType,
        public readonly string $schemaKey,
        public readonly array $structuredData,
    ) {}
}
