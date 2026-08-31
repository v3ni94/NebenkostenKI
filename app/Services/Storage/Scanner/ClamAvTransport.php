<?php

declare(strict_types=1);

namespace App\Services\Storage\Scanner;

/**
 * Uebertragungsweg zum ClamAV-Daemon.
 *
 * Die Abstraktion existiert, damit die Auswertung der Antwort ohne echten
 * clamd getestet werden kann. Sie kapselt ausschliesslich die Uebertragung.
 */
interface ClamAvTransport
{
    /**
     * Uebertraegt die Datei per INSTREAM an clamd und gibt die Rohantwort
     * zurueck, zum Beispiel "stream: OK" oder "stream: Eicar-Test-Signature
     * FOUND".
     *
     * @throws ClamAvTransportException
     */
    public function instream(string $absolutePath): string;
}
