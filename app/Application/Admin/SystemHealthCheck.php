<?php

declare(strict_types=1);

namespace App\Application\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use Throwable;

/**
 * Healthchecks fuer Datenbank, SFTP, Mail und Storage (Masterprompt 20, 22).
 *
 * VERBINDLICH
 *
 *  1. Es wird NIEMALS ein Secret ausgegeben, auch nicht teilweise maskiert.
 *     Gemeldet werden ausschliesslich erreichbar ja oder nein, die Version und
 *     die Fehlerklasse. Auch Host, Benutzername, Pfad und Endpunkt bleiben
 *     ausserhalb der Ausgabe.
 *  2. Laravel verwendet fuer MariaDB technisch den MySQL-PDO-Treiber. Der
 *     Healthcheck gibt deshalb die TATSAECHLICHE Serverversion aus und prueft
 *     auf 10.11 oder ein unterstuetztes 11.x.
 *  3. Ein fehlgeschlagener Check wirft nicht, sondern meldet den Zustand. Der
 *     Adminbereich muss auch dann bedienbar bleiben, wenn ein Dienst haengt.
 */
final class SystemHealthCheck
{
    /**
     * Unterstuetzte MariaDB-Hauptversionen nach Masterprompt 3.3 und 22.
     */
    public const string MARIADB_LTS = '10.11';

    /**
     * @return list<HealthProbe>
     */
    public function all(): array
    {
        return [
            $this->database(),
            $this->storage(),
            $this->sftp(),
            $this->mail(),
        ];
    }

    /**
     * Datenbank mit tatsaechlicher Serverversion.
     */
    public function database(): HealthProbe
    {
        $driver = $this->configString('database.default') ?? 'mysql';

        try {
            $pdo = DB::connection()->getPdo();
            $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            $version = is_scalar($version) ? (string) $version : null;
            $driverName = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $driverName = is_scalar($driverName) ? (string) $driverName : $driver;
        } catch (Throwable $exception) {
            return new HealthProbe(
                'Datenbank',
                true,
                false,
                null,
                $this->errorClass($exception),
                'Die Datenbank ist nicht erreichbar.',
            );
        }

        if ($driverName === 'sqlite') {
            return new HealthProbe(
                'Datenbank',
                true,
                true,
                'SQLite '.($version ?? 'unbekannt'),
                null,
                'Die Verbindung nutzt SQLite. Zielsystem ist MariaDB '.self::MARIADB_LTS
                    .' oder ein unterstütztes 11.x. Die Versionsprüfung greift erst auf dem Zielsystem.',
                null,
            );
        }

        $supported = $version !== null && self::isSupportedMariaDbVersion($version);

        return new HealthProbe(
            'Datenbank',
            true,
            true,
            $version,
            null,
            $supported
                ? 'Die Serverversion ist freigegeben.'
                : sprintf(
                    'Die Serverversion ist nicht als unterstützt hinterlegt. Verbindlich sind MariaDB %s '
                    .'oder ein unterstütztes 11.x. Bitte vor Livegang klären.',
                    self::MARIADB_LTS,
                ),
            $supported,
        );
    }

    /**
     * Erkennt MariaDB 10.11 und unterstuetzte 11.x.
     *
     * MariaDB meldet sich ueber den MySQL-Treiber typischerweise als
     * "10.11.6-MariaDB" oder "11.4.2-MariaDB".
     */
    public static function isSupportedMariaDbVersion(string $version): bool
    {
        if (! Str::contains(Str::lower($version), 'mariadb')) {
            return false;
        }

        if (Str::startsWith($version, self::MARIADB_LTS.'.') || $version === self::MARIADB_LTS) {
            return true;
        }

        return preg_match('/^11\.\d+/', $version) === 1;
    }

    /**
     * Ablage der erzeugten Ergebnisartefakte. Geprueft wird mit einer
     * Probedatei ohne Inhalt von Belang.
     */
    public function storage(): HealthProbe
    {
        $disk = $this->configString('filesystems.default') ?? 'local';

        try {
            $key = 'healthcheck/'.Str::lower((string) Str::ulid()).'.txt';
            $filesystem = Storage::disk($disk);
            $filesystem->put($key, 'ok');
            $exists = $filesystem->exists($key);
            $filesystem->delete($key);
        } catch (Throwable $exception) {
            return new HealthProbe(
                'Storage',
                true,
                false,
                null,
                $this->errorClass($exception),
                'Die Ablage der Ergebnisartefakte ist nicht beschreibbar.',
            );
        }

        return new HealthProbe(
            'Storage',
            true,
            $exists,
            null,
            null,
            $exists
                ? 'Schreiben, Lesen und Löschen einer Probedatei ist möglich.'
                : 'Die Probedatei war nach dem Schreiben nicht lesbar.',
        );
    }

    /**
     * SFTP-Ziel. Ohne hinterlegte Verbindung wird nicht verbunden.
     */
    public function sftp(): HealthProbe
    {
        $configured = $this->configString('filesystems.disks.sftp.host') !== null
            && $this->configString('filesystems.disks.sftp.username') !== null;

        if (! $configured) {
            return new HealthProbe(
                'SFTP',
                false,
                null,
                null,
                null,
                'Es ist kein SFTP-Ziel hinterlegt. Der Zugang wird vom Betreiber bereitgestellt.',
            );
        }

        try {
            Storage::disk('sftp')->directories('/');
        } catch (Throwable $exception) {
            return new HealthProbe(
                'SFTP',
                true,
                false,
                null,
                $this->errorClass($exception),
                'Das SFTP-Ziel ist nicht erreichbar.',
            );
        }

        return new HealthProbe(
            'SFTP',
            true,
            true,
            null,
            null,
            'Das Zielverzeichnis ist lesbar.',
        );
    }

    /**
     * Mailversand. Es wird keine Verbindung aufgebaut und keine Testmail
     * versendet, damit der Adminbereich keinen ungewollten Versand ausloest.
     */
    public function mail(): HealthProbe
    {
        $mailer = $this->configString('mail.default') ?? 'smtp';

        if (in_array($mailer, ['array', 'log', 'null'], true)) {
            return new HealthProbe(
                'Mail',
                true,
                null,
                null,
                null,
                sprintf(
                    'Der Versandtreiber ist "%s". Es verlässt keine Nachricht das System. Produktiv ist SMTP '
                    .'über das Postfach des Betreibers verbindlich.',
                    $mailer,
                ),
            );
        }

        $missing = [];

        foreach (['host', 'port', 'username'] as $key) {
            if ($this->configString('mail.mailers.'.$mailer.'.'.$key) === null) {
                $missing[] = $key;
            }
        }

        if ($this->configString('mail.from.address') === null) {
            $missing[] = 'from.address';
        }

        if ($missing !== []) {
            return new HealthProbe(
                'Mail',
                false,
                null,
                null,
                null,
                'Die Versandkonfiguration ist unvollständig. Es fehlen Angaben: '.implode(', ', $missing)
                    .'. Werte werden nicht angezeigt.',
            );
        }

        return new HealthProbe(
            'Mail',
            true,
            null,
            null,
            null,
            'Die Versandkonfiguration ist vollständig. Eine Verbindung wird hier bewusst nicht aufgebaut, '
                .'damit der Adminbereich keinen Versand auslöst.',
        );
    }

    private function errorClass(Throwable $exception): string
    {
        // Nur der Klassenname der Ausnahme. Der Meldungstext kann Host, Pfad
        // oder Zugangsdaten enthalten und wird deshalb nicht uebernommen.
        return class_basename($exception);
    }

    private function configString(string $key): ?string
    {
        $value = config($key);

        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
