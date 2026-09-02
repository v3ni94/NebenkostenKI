<?php

declare(strict_types=1);

namespace App\Services\Storage\Scanner;

/**
 * Uebertragung an clamd ueber TCP oder einen Unix-Socket.
 *
 * Der Endpunkt kommt aus MALWARE_SCANNER_ENDPOINT, zum Beispiel
 * "tcp://127.0.0.1:3310" oder "unix:///var/run/clamav/clamd.ctl".
 *
 * Verwendet wird das INSTREAM-Kommando, damit clamd die Datei nicht selbst
 * lesen muss. Der Quarantaenebereich bleibt dadurch fuer den Scannerprozess
 * unzugaenglich.
 *
 * DATENSCHUTZ: Der Inhalt wird blockweise gesendet und nicht zwischengespeichert.
 */
final class SocketClamAvTransport implements ClamAvTransport
{
    private const CHUNK_BYTES = 32768;

    public function __construct(
        private readonly string $endpoint,
        private readonly int $timeoutSeconds = 20,
    ) {}

    public function instream(mixed $source): string
    {
        if ($this->endpoint === '') {
            throw new ClamAvTransportException('Es ist kein ClamAV-Endpunkt konfiguriert.');
        }

        if (is_resource($source)) {
            return $this->send($source, false);
        }

        if (! is_string($source)) {
            throw new ClamAvTransportException('Die zu pruefende Datei konnte nicht gelesen werden.');
        }

        $file = fopen($source, 'rb');

        if ($file === false) {
            throw new ClamAvTransportException('Die zu pruefende Datei konnte nicht gelesen werden.');
        }

        return $this->send($file, true);
    }

    /**
     * @param  resource  $file
     */
    private function send($file, bool $closeFile): string
    {

        $errorNumber = 0;
        $errorMessage = '';

        $socket = @stream_socket_client(
            $this->endpoint,
            $errorNumber,
            $errorMessage,
            $this->timeoutSeconds,
        );

        if ($socket === false) {
            if ($closeFile) {
                fclose($file);
            }

            throw new ClamAvTransportException(sprintf(
                'Die Verbindung zum ClamAV-Endpunkt ist fehlgeschlagen (Code %d).',
                $errorNumber
            ));
        }

        stream_set_timeout($socket, $this->timeoutSeconds);

        try {
            fwrite($socket, "zINSTREAM\0");

            while (! feof($file)) {
                $block = fread($file, self::CHUNK_BYTES);

                if ($block === false || $block === '') {
                    continue;
                }

                fwrite($socket, pack('N', strlen($block)));
                fwrite($socket, $block);
            }

            // Nullaenge beendet den Stream.
            fwrite($socket, pack('N', 0));

            $response = (string) stream_get_contents($socket);
        } finally {
            if ($closeFile) {
                fclose($file);
            }

            fclose($socket);
        }

        if (trim($response) === '') {
            throw new ClamAvTransportException('Der ClamAV-Endpunkt hat keine Antwort geliefert.');
        }

        return trim(str_replace("\0", '', $response));
    }
}
