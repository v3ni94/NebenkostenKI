<?php

declare(strict_types=1);

namespace App\Services\Storage;

use RuntimeException;

/**
 * Schluesselgebundener Fingerabdruck zur Dublettenerkennung.
 *
 * WARUM KEIN REINER SHA-256 DAUERHAFT GESPEICHERT WIRD
 * ----------------------------------------------------
 * Ein SHA-256 des Dateiinhalts ist ein weltweit eindeutiges
 * Wiedererkennungsmerkmal der Originaldatei. Er erlaubt es, mit einer
 * Vergleichsdatei zu beweisen, welches Dokument ein Nutzer hochgeladen hat,
 * und zwar auch Jahre nach der Loeschung des Originals. Damit waere der Hash
 * ein dauerhaftes Datum ueber ein gelöschtes Dokument und wuerde die
 * Loeschzusage aus Abschnitt 6.4 und ARCHITECTURE.md Abschnitt 5.2
 * unterlaufen.
 *
 * Gespeichert wird deshalb ausschliesslich ein HMAC-SHA-256 mit einem aus
 * APP_KEY abgeleiteten, anwendungseigenen Schluessel. Ohne diesen Schluessel
 * ist der Wert nicht nachrechenbar. Fuer die Dublettenerkennung innerhalb
 * eines Abrechnungslaufs genuegt er vollstaendig, weil dort nur Werte
 * derselben Installation verglichen werden.
 *
 * Der reine SHA-256 lebt ausschliesslich im Arbeitsspeicher dieser Klasse und
 * wird nach der Ableitung verworfen. Er wird nicht zurueckgegeben, nicht
 * protokolliert und nicht in einen Queue-Payload geschrieben.
 */
final class FingerprintFactory
{
    /**
     * Kontextkennung der Schluesselableitung. Ein Wechsel des Kontexts
     * entwertet alle bestehenden Fingerabdruecke, deshalb ist der Wert fest.
     */
    private const HKDF_INFO = 'smart-abrechnen:dokument-fingerabdruck:v1';

    private const READ_CHUNK_BYTES = 1024 * 1024;

    /**
     * Fingerabdruck einer Datei auf der Platte. Die Datei wird in Bloecken
     * gelesen, damit auch 25 MB ohne Speicherlast verarbeitet werden.
     */
    public function forFile(string $absolutePath): string
    {
        if (! is_file($absolutePath)) {
            throw new RuntimeException('Fuer den Fingerabdruck ist keine lesbare Quelldatei vorhanden.');
        }

        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Die Quelldatei konnte fuer den Fingerabdruck nicht geoeffnet werden.');
        }

        $context = hash_init('sha256');

        try {
            while (! feof($handle)) {
                $block = fread($handle, self::READ_CHUNK_BYTES);

                if ($block === false) {
                    throw new RuntimeException('Die Quelldatei konnte fuer den Fingerabdruck nicht gelesen werden.');
                }

                hash_update($context, $block);
            }
        } finally {
            fclose($handle);
        }

        // Der reine SHA-256 existiert nur in dieser lokalen Variablen.
        $sha256 = hash_final($context);

        $hmac = $this->bind($sha256);

        unset($sha256);

        return $hmac;
    }

    /**
     * Fingerabdruck eines im Speicher liegenden Inhalts. Nur fuer kleine
     * Inhalte und Tests vorgesehen.
     */
    public function forContents(string $contents): string
    {
        $sha256 = hash('sha256', $contents);

        $hmac = $this->bind($sha256);

        unset($sha256);

        return $hmac;
    }

    /**
     * Bindet einen Inhaltshash an den anwendungseigenen Schluessel.
     */
    private function bind(string $sha256): string
    {
        return hash_hmac('sha256', $sha256, $this->derivedKey());
    }

    /**
     * Aus APP_KEY abgeleiteter, dedizierter Fingerabdruckschluessel. APP_KEY
     * selbst wird nicht direkt verwendet, damit ein Fingerabdruck keine
     * Rueckschluesse auf den Verschluesselungsschluessel erlaubt.
     */
    private function derivedKey(): string
    {
        $appKey = config('app.key');

        if (! is_string($appKey) || $appKey === '') {
            throw new RuntimeException(
                'APP_KEY ist nicht gesetzt. Ohne Anwendungsschluessel darf kein Fingerabdruck gebildet werden.'
            );
        }

        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            $appKey = $decoded === false ? $appKey : $decoded;
        }

        return hash_hkdf('sha256', $appKey, 32, self::HKDF_INFO);
    }
}
