<?php

declare(strict_types=1);

namespace App\Application\Install;

use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Inhalt des Releasepakets (Masterprompt 21.1).
 *
 * Die Klasse ist frei von Framework-Abhaengigkeiten, weil sie auch von
 * bin/deploy-sftp.php ohne gebootete Anwendung verwendet wird. Dieselbe Regel
 * gilt fuer .github/workflows/deploy.yml; die Ausschlussliste dort ist aus
 * dieser Klasse abgeleitet und im Unit-Test abgesichert.
 *
 * ENTHALTEN: Anwendungscode, vendor/, gebaute Assets (public/build),
 * Verzeichnisgeruest unter storage/, Healthcheck und Versionsmetadaten.
 *
 * NICHT ENTHALTEN: .env in jeder Form, Tests, temporaere Uploads, Logs,
 * node_modules, Quelltexte des Frontends, Entwicklungswerkzeuge, das
 * Repository selbst.
 */
final class ReleasePackage
{
    /**
     * Pfade relativ zur Projektwurzel, die nie ins Paket gelangen. Ein
     * Eintrag mit abschliessendem Schraegstrich ist ein Verzeichnis.
     *
     * @var list<string>
     */
    public const array EXCLUDED_PATHS = [
        '.git/',
        '.github/',
        '.claude/',
        '.idea/',
        '.vscode/',
        'node_modules/',
        'tests/',
        'docs/',
        'build-release/',
        'resources/css/',
        'resources/js/',
        'storage/logs/',
        'storage/app/temporary-uploads/',
        'storage/app/private/',
        'storage/framework/cache/data/',
        'storage/framework/sessions/',
        'storage/framework/testing/',
        'storage/framework/views/',
        'bootstrap/cache/',
        'public/hot',
        'public/storage',
        '.phpunit.result.cache',
        '.phpunit.cache/',
        'phpunit.xml',
        'phpstan.neon',
        'pint.json',
        'package.json',
        'package-lock.json',
        'vite.config.js',
        '.editorconfig',
        '.gitattributes',
        '.gitignore',
    ];

    /**
     * Dateinamen, die unabhaengig vom Verzeichnis ausgeschlossen sind.
     *
     * @var list<string>
     */
    public const array EXCLUDED_BASENAME_PATTERNS = [
        '/^\.env(\..*)?$/',
        '/\.log$/',
        '/\.sqlite$/',
        '/^\.DS_Store$/',
        '/^Thumbs\.db$/',
        '/^auth\.json$/',
    ];

    /**
     * Dateien, die im Paket vorhanden sein muessen, damit die Auslieferung
     * ueberhaupt beginnt.
     *
     * @var list<string>
     */
    public const array REQUIRED_FILES = [
        'artisan',
        'public/index.php',
        'public/.htaccess',
        'vendor/autoload.php',
        'public/build/manifest.json',
        'bootstrap/app.php',
    ];

    /**
     * Darf der Pfad (relativ, mit Schraegstrichen) ins Paket?
     */
    public function allows(string $relativePath, bool $isDirectory = false): bool
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

        if ($relativePath === '') {
            return true;
        }

        $basename = basename($relativePath);

        foreach (self::EXCLUDED_BASENAME_PATTERNS as $pattern) {
            if (preg_match($pattern, $basename) === 1) {
                return false;
            }
        }

        $candidate = $isDirectory ? $relativePath.'/' : $relativePath;

        foreach (self::EXCLUDED_PATHS as $excluded) {
            if (str_ends_with($excluded, '/')) {
                if ($candidate === $excluded || str_starts_with($candidate, $excluded)) {
                    return false;
                }

                continue;
            }

            if ($relativePath === $excluded) {
                return false;
            }
        }

        return true;
    }

    /**
     * Alle Dateien des Pakets, relativ zur Quelle, sortiert.
     *
     * @return list<string>
     */
    public function files(string $sourceDirectory): array
    {
        $source = rtrim(realpath($sourceDirectory) ?: $sourceDirectory, '/');

        $directory = new RecursiveDirectoryIterator(
            $source,
            RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::CURRENT_AS_FILEINFO,
        );

        $filtered = new RecursiveCallbackFilterIterator(
            $directory,
            function (SplFileInfo $file) use ($source): bool {
                $relative = ltrim(substr($file->getPathname(), strlen($source)), '/');

                return $this->allows($relative, $file->isDir());
            },
        );

        $files = [];

        foreach (new RecursiveIteratorIterator($filtered, RecursiveIteratorIterator::LEAVES_ONLY) as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $files[] = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($source))), '/');
        }

        sort($files);

        return $files;
    }

    /**
     * Fehlende Pflichtdateien der Quelle.
     *
     * @return list<string>
     */
    public function missingRequiredFiles(string $sourceDirectory): array
    {
        $missing = [];

        foreach (self::REQUIRED_FILES as $file) {
            if (! is_file(rtrim($sourceDirectory, '/').'/'.$file)) {
                $missing[] = $file;
            }
        }

        return $missing;
    }

    /**
     * Ausschlussmuster fuer rsync, abgeleitet aus derselben Liste.
     *
     * @return list<string>
     */
    public function rsyncExcludes(): array
    {
        $excludes = [];

        foreach (self::EXCLUDED_PATHS as $path) {
            $excludes[] = '/'.rtrim($path, '/');
        }

        return array_merge($excludes, ['.env', '.env.*', '*.log', '*.sqlite', '.DS_Store', 'Thumbs.db', 'auth.json']);
    }
}
