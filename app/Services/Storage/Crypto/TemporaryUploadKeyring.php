<?php

declare(strict_types=1);

namespace App\Services\Storage\Crypto;

use App\Models\TemporaryUpload;
use RuntimeException;
use Throwable;

/**
 * Verwaltet die Dateischluessel des Kurzzeitbereichs je Upload-Praefix.
 *
 * SCHLUESSELABLEITUNG
 * -------------------
 * Hauptschluessel = HKDF-SHA-256(APP_KEY, Zweck "smart-abrechnen:temporary-upload-v1")
 *
 * Jeder Upload erhaelt einen zufaelligen Dateischluessel. Gespeichert wird in
 * temporary_uploads.encryption_key_wrapped ausschliesslich der mit dem
 * Hauptschluessel umhuellte Dateischluessel. Der Klartextschluessel lebt nur
 * im Arbeitsspeicher des laufenden Prozesses.
 *
 * FOLGE EINES APP_KEY-WECHSELS: Laufende Uploads werden unlesbar, weil der
 * Hauptschluessel nicht mehr zum umhuellten Dateischluessel passt. Das ist
 * fuer einen Kurzzeitbereich mit hoechstens 120 Minuten Frist akzeptabel:
 * betroffene Dokumente scheitern mit einem klaren Fehler, ihre Dateien werden
 * ueber TTL und Loeschpfad entfernt, der Nutzer laedt erneut hoch. Eine
 * Schluesselrotation mit Neuverschluesselung wird bewusst nicht angeboten.
 *
 * PROZESSCACHE: Schluessel werden je Praefix im Prozess zwischengespeichert,
 * damit die Zusammensetzung nicht je Block die Datenbank fragt. Der Cache ist
 * statisch, weil der Kurzzeitbereich ueber mehrere Instanzen dieser Klasse
 * hinweg dieselben Schluessel sehen muss. Nach Loeschung eines Praefixes wird
 * der Eintrag entfernt.
 */
final class TemporaryUploadKeyring
{
    private const HKDF_INFO = 'smart-abrechnen:temporary-upload-v1';

    public const COLUMN = 'encryption_key_wrapped';

    /**
     * @var array<string, string> Praefix => Klartext-Dateischluessel
     */
    private static array $keys = [];

    public function __construct(private readonly TemporaryUploadCipher $cipher) {}

    public function cipher(): TemporaryUploadCipher
    {
        return $this->cipher;
    }

    /**
     * Erzeugt einen neuen Dateischluessel fuer ein frisch vergebenes Praefix.
     */
    public function create(string $prefix): void
    {
        self::$keys[$prefix] = $this->cipher->newFileKey();
    }

    /**
     * Umhuellter Dateischluessel fuer die Spalte temporary_uploads.encryption_key_wrapped.
     */
    public function wrappedKeyFor(string $prefix): string
    {
        return $this->cipher->wrapKey($this->fileKeyForWriting($prefix), $this->masterKey());
    }

    /**
     * Dateischluessel zum Schreiben. Fehlt fuer das Praefix noch ein Schluessel,
     * wird einer erzeugt; existiert bereits ein Datensatz ohne Schluessel, wird
     * der neue Schluessel dort nachgetragen.
     *
     * Ein Praefix ohne Datensatz erhaelt seinen Schluessel nur im Prozess. Endet
     * der Prozess, ist die Datei unlesbar und wird als verwaister Rest durch den
     * TTL-Cleanup entfernt. Das ist gewollt: fail closed.
     */
    public function fileKeyForWriting(string $prefix): string
    {
        $cached = self::$keys[$prefix] ?? null;

        if ($cached !== null) {
            return $cached;
        }

        $stored = $this->loadWrappedKey($prefix);

        if ($stored !== null) {
            return self::$keys[$prefix] = $this->unwrap($stored);
        }

        $this->create($prefix);

        $this->persistIfRecordExists($prefix);

        return self::$keys[$prefix];
    }

    /**
     * Dateischluessel zum Lesen. Ohne bekannten Schluessel wird strikt
     * abgelehnt, niemals ein neuer erzeugt.
     *
     * @throws CipherIntegrityException
     */
    public function fileKeyForReading(string $prefix): string
    {
        $cached = self::$keys[$prefix] ?? null;

        if ($cached !== null) {
            return $cached;
        }

        $stored = $this->loadWrappedKey($prefix);

        if ($stored === null) {
            throw CipherIntegrityException::keyUnavailable();
        }

        return self::$keys[$prefix] = $this->unwrap($stored);
    }

    public function forget(string $prefix): void
    {
        if (isset(self::$keys[$prefix])) {
            $key = self::$keys[$prefix];
            unset(self::$keys[$prefix]);

            if (function_exists('sodium_memzero')) {
                sodium_memzero($key);
            }
        }
    }

    /**
     * Nur fuer Tests: leert den Prozesscache, um das Verhalten ohne
     * zwischengespeicherten Schluessel zu pruefen.
     */
    public static function flushProcessCache(): void
    {
        self::$keys = [];
    }

    /**
     * @throws CipherIntegrityException
     */
    private function unwrap(string $wrapped): string
    {
        return TemporaryUploadCipherFactory::forWrappedKey($wrapped)->unwrapKey($wrapped, $this->masterKey());
    }

    private function loadWrappedKey(string $prefix): ?string
    {
        try {
            $value = TemporaryUpload::query()
                ->where('storage_key', $prefix)
                ->where('is_tombstone', false)
                ->value(self::COLUMN);
        } catch (Throwable) {
            // Ohne Datenbank (reine Unit-Tests) gibt es keinen gespeicherten
            // Schluessel. Der Aufrufer behandelt das wie "nicht vorhanden".
            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function persistIfRecordExists(string $prefix): void
    {
        try {
            TemporaryUpload::query()
                ->where('storage_key', $prefix)
                ->where('is_tombstone', false)
                ->whereNull(self::COLUMN)
                ->update([self::COLUMN => $this->cipher->wrapKey(self::$keys[$prefix], $this->masterKey())]);
        } catch (Throwable) {
            // Kein Datensatz oder keine Datenbank: der Schluessel bleibt im
            // Prozess, siehe Klassenkommentar.
        }
    }

    /**
     * Aus APP_KEY abgeleiteter Hauptschluessel. APP_KEY selbst wird nicht
     * direkt verwendet, damit der Kurzzeitbereich einen eigenen Schluessel
     * hat, der weder mit Sessions noch mit Fingerabdruecken geteilt wird.
     */
    private function masterKey(): string
    {
        $appKey = config('app.key');

        if (! is_string($appKey) || $appKey === '') {
            throw new RuntimeException(
                'APP_KEY ist nicht gesetzt. Ohne Anwendungsschluessel darf der Kurzzeitbereich nicht beschrieben werden.'
            );
        }

        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            $appKey = $decoded === false ? $appKey : $decoded;
        }

        return hash_hkdf('sha256', $appKey, 32, self::HKDF_INFO);
    }
}
