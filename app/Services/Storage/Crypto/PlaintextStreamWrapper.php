<?php

declare(strict_types=1);

namespace App\Services\Storage\Crypto;

use RuntimeException;

/**
 * Stellt einen PlaintextReader als PHP-Stream bereit.
 *
 * Damit koennen fread-basierte Verbraucher (Magic-Byte-Pruefung,
 * Seitenzaehlung, Fingerabdruck, ClamAV-INSTREAM, HTTP-Upload an einen
 * externen Scanner) den Klartext sequentiell und mit konstantem Speicher
 * lesen, ohne dass er je als Datei existiert.
 *
 * Der Strom ist nicht positionierbar und liefert stream_cast() = false, damit
 * kein Verbraucher ihn in eine temporaere Klartextdatei kopieren kann.
 * Integritaetsfehler des Readers werden als Ausnahme durch fread() gereicht.
 */
final class PlaintextStreamWrapper
{
    public const PROTOCOL = 'sa-klartext';

    /**
     * Wird von PHP gesetzt.
     *
     * @var resource|null
     */
    public $context;

    /**
     * @var array<int, PlaintextReader>
     */
    private static array $pending = [];

    private static int $nextId = 1;

    private ?PlaintextReader $reader = null;

    private int $position = 0;

    /**
     * @return resource
     */
    public static function open(PlaintextReader $reader)
    {
        self::register();

        $id = self::$nextId++;
        self::$pending[$id] = $reader;

        $handle = fopen(self::PROTOCOL.'://'.$id, 'rb');

        if ($handle === false) {
            unset(self::$pending[$id]);
            $reader->close();

            throw new RuntimeException('Der Klartextstrom konnte nicht geoeffnet werden.');
        }

        return $handle;
    }

    private static function register(): void
    {
        if (! in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $id = (int) substr($path, strlen(self::PROTOCOL) + 3);

        if (! isset(self::$pending[$id])) {
            return false;
        }

        $this->reader = self::$pending[$id];
        unset(self::$pending[$id]);

        return true;
    }

    public function stream_read(int $count): string
    {
        if ($this->reader === null) {
            return '';
        }

        $data = $this->reader->read($count);
        $this->position += strlen($data);

        return $data;
    }

    public function stream_eof(): bool
    {
        return $this->reader === null || $this->reader->eof();
    }

    public function stream_close(): void
    {
        $this->reader?->close();
        $this->reader = null;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        return false;
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return [
            'mode' => 0100600,
            'size' => $this->reader?->size() ?? 0,
        ];
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_cast(int $castAs): bool
    {
        return false;
    }
}
