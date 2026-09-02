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
     * Uebertraegt den Inhalt per INSTREAM an clamd und gibt die Rohantwort
     * zurueck, zum Beispiel "stream: OK" oder "stream: Eicar-Test-Signature
     * FOUND".
     *
     * @param  string|resource  $source  absoluter Pfad einer Klartextdatei oder
     *                                   ein lesbarer Klartextstrom; ein Strom
     *                                   wird nicht geschlossen
     *
     * @throws ClamAvTransportException
     */
    public function instream(mixed $source): string;
}
