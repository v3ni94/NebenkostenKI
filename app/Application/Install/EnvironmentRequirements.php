<?php

declare(strict_types=1);

namespace App\Application\Install;

/**
 * Technische Voraussetzungen der Anwendung auf dem Zielsystem (Masterprompt
 * 3.1, Profil A).
 *
 * Die Klasse prueft nur und veraendert nichts. Sie ist bewusst ohne
 * Framework-Abhaengigkeit, damit sie auch dann ein Ergebnis liefert, wenn die
 * Anwendung wegen einer fehlenden Voraussetzung nicht vollstaendig startet.
 */
final class EnvironmentRequirements
{
    public const string MIN_PHP_VERSION = '8.3.0';

    /**
     * @var list<string>
     */
    public const array REQUIRED_EXTENSIONS = [
        'pdo_mysql', 'mbstring', 'gd', 'intl', 'zip', 'openssl',
        'fileinfo', 'ctype', 'json', 'tokenizer', 'dom',
    ];

    /**
     * Verzeichnisse, die zur Laufzeit beschreibbar sein muessen. Relativ zum
     * Anwendungsverzeichnis; der Speicherpfad kann ueber LARAVEL_STORAGE_PATH
     * ausserhalb liegen und wird deshalb absolut uebergeben.
     *
     * @param  list<string>  $writableDirectories
     * @param  list<string>  $loadedExtensions
     */
    public function __construct(
        private readonly array $writableDirectories,
        private readonly ?string $appKey,
        private readonly string $phpVersion = PHP_VERSION,
        private readonly ?array $loadedExtensions = null,
    ) {}

    /**
     * @return list<RequirementResult>
     */
    public function check(): array
    {
        $results = [$this->phpVersion()];

        foreach ($this->missingExtensions() as $extension) {
            $results[] = new RequirementResult(
                'PHP-Erweiterung '.$extension,
                false,
                sprintf(
                    'Die PHP-Erweiterung "%s" ist nicht geladen. Bitte im IONOS-Control-Center unter '
                    .'PHP-Einstellungen aktivieren oder eine PHP-Version mit dieser Erweiterung waehlen.',
                    $extension,
                ),
            );
        }

        if ($this->missingExtensions() === []) {
            $results[] = new RequirementResult(
                'PHP-Erweiterungen',
                true,
                'Alle benoetigten Erweiterungen sind geladen: '.implode(', ', self::REQUIRED_EXTENSIONS).'.',
            );
        }

        foreach ($this->writableDirectories as $directory) {
            $results[] = $this->writable($directory);
        }

        $results[] = $this->appKey();

        return $results;
    }

    public function fulfilled(): bool
    {
        foreach ($this->check() as $result) {
            if (! $result->fulfilled) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public function missingExtensions(): array
    {
        $loaded = $this->loadedExtensions ?? get_loaded_extensions();
        $loaded = array_map('strtolower', $loaded);

        return array_values(array_filter(
            self::REQUIRED_EXTENSIONS,
            static fn (string $extension): bool => ! in_array($extension, $loaded, true),
        ));
    }

    private function phpVersion(): RequirementResult
    {
        $ok = version_compare($this->phpVersion, self::MIN_PHP_VERSION, '>=');

        return new RequirementResult(
            'PHP-Version',
            $ok,
            $ok
                ? sprintf('PHP %s erfuellt die Mindestversion %s.', $this->phpVersion, self::MIN_PHP_VERSION)
                : sprintf(
                    'PHP %s ist zu alt. Erforderlich ist mindestens PHP %s. Bitte im IONOS-Control-Center '
                    .'die PHP-Version fuer Web und CLI umstellen.',
                    $this->phpVersion,
                    self::MIN_PHP_VERSION,
                ),
        );
    }

    private function writable(string $directory): RequirementResult
    {
        $name = 'Schreibrecht '.basename(dirname($directory)).'/'.basename($directory);

        if (! is_dir($directory)) {
            return new RequirementResult(
                $name,
                false,
                sprintf('Das Verzeichnis %s fehlt. Bitte anlegen und fuer PHP beschreibbar machen.', $directory),
            );
        }

        if (! is_writable($directory)) {
            return new RequirementResult(
                $name,
                false,
                sprintf(
                    'Das Verzeichnis %s ist nicht beschreibbar. Bitte die Rechte ueber den SFTP-Client '
                    .'setzen (Besitzer lesen, schreiben, ausfuehren).',
                    $directory,
                ),
            );
        }

        return new RequirementResult($name, true, sprintf('%s ist beschreibbar.', $directory));
    }

    private function appKey(): RequirementResult
    {
        $key = $this->appKey === null ? '' : trim($this->appKey);

        if ($key === '') {
            return new RequirementResult(
                'APP_KEY',
                false,
                'APP_KEY ist nicht gesetzt. Bitte lokal mit "php artisan key:generate --show" erzeugen und den '
                .'Wert in die .env des Zielsystems eintragen. Der Schluessel darf spaeter nicht mehr wechseln, '
                .'weil verschluesselte Daten sonst unlesbar werden.',
            );
        }

        $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        $length = $raw === false ? 0 : strlen($raw);

        if ($length < 32) {
            return new RequirementResult(
                'APP_KEY',
                false,
                'APP_KEY ist zu kurz oder ungueltig kodiert. Erwartet wird ein 32-Byte-Schluessel im Format '
                .'"base64:...". Bitte mit "php artisan key:generate --show" neu erzeugen.',
            );
        }

        return new RequirementResult('APP_KEY', true, 'APP_KEY ist gesetzt.');
    }
}
