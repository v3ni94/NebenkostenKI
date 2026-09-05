<?php

declare(strict_types=1);

namespace App\Application\Install;

/**
 * Legt die Verzeichnisstruktur unter storage/ an, die Laravel und die
 * Anwendung zur Laufzeit erwarten.
 *
 * Auf IONOS werden Releases per SFTP hochgeladen. Leere Verzeichnisse gehen
 * dabei leicht verloren, und ein gemeinsames storage/ unter shared/ beginnt
 * leer. Der Lauf ist idempotent: vorhandene Verzeichnisse bleiben unberuehrt.
 *
 * Es wird KEIN oeffentlicher Speicherlink (public/storage) angelegt. Die
 * Anwendung liefert keine Dateien ueber einen oeffentlichen Pfad aus, jede
 * Ergebnisdatei laeuft ueber autorisierte Streaming-Routen (Masterprompt 3.4).
 */
final class StorageDirectories
{
    /**
     * Relativ zum Speicherpfad.
     *
     * @var list<string>
     */
    public const array RELATIVE = [
        'app',
        'app/private',
        'app/temporary-uploads',
        'framework',
        'framework/cache',
        'framework/cache/data',
        'framework/sessions',
        'framework/testing',
        'framework/views',
        'logs',
    ];

    /**
     * @return list<string> neu angelegte Verzeichnisse, absolut
     */
    public function ensure(string $storagePath): array
    {
        $created = [];

        foreach (self::RELATIVE as $relative) {
            $absolute = rtrim($storagePath, '/').'/'.$relative;

            if (is_dir($absolute)) {
                continue;
            }

            if (! @mkdir($absolute, 0750, true) && ! is_dir($absolute)) {
                throw new \RuntimeException(sprintf(
                    'Das Verzeichnis %s konnte nicht angelegt werden. Bitte Schreibrechte pruefen.',
                    $absolute,
                ));
            }

            $created[] = $absolute;
        }

        // Der Kurzzeitbereich darf nie ueber den Webserver erreichbar sein.
        // Eine .htaccess im Verzeichnis ist die zweite Verteidigungslinie,
        // falls er wider Erwarten unterhalb des Document Roots liegt.
        $htaccess = rtrim($storagePath, '/').'/app/temporary-uploads/.htaccess';

        if (! is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\n");
        }

        return $created;
    }
}
