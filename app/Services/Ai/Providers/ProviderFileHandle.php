<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

/**
 * Referenz auf eine temporaer beim Provider angelegte Datei.
 *
 * Die Referenz lebt ausschliesslich fuer die Dauer eines Extraktionslaufs.
 * Sie wird nicht persistiert (Abschnitt 6.4: temporaere
 * KI-Provider-Datei-IDs werden nach Abschluss der Verarbeitung nicht
 * dauerhaft gespeichert). Fuer das Loeschprotokoll wird ausschliesslich der
 * gekuerzte Hash der ID uebernommen.
 *
 * expiresAfterSeconds dokumentiert die beim Provider gesetzte Kurzzeitfrist.
 * Sie ersetzt nicht das aktive Loeschen nach validierter Extraktion.
 */
final class ProviderFileHandle
{
    public function __construct(
        public readonly string $fileId,
        public readonly ?int $expiresAfterSeconds = null,
    ) {}

    /**
     * @return array<string, scalar|null>
     */
    public function __debugInfo(): array
    {
        return [
            'fileIdHash' => substr(hash('sha256', $this->fileId), 0, 16),
            'fileId' => '[redigiert]',
            'expiresAfterSeconds' => $this->expiresAfterSeconds,
        ];
    }
}
