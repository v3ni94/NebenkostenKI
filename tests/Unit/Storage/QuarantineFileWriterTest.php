<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Services\Storage\Crypto\EncryptingWriter;
use App\Services\Storage\QuarantineFileWriter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Der Schreibvorgang darf bei einem Fehler im Abschluss keine Zwischendatei
 * im Kurzzeitbereich liegen lassen.
 */
final class QuarantineFileWriterTest extends TestCase
{
    public function test_fehler_beim_endblock_verwirft_die_zwischendatei_und_reicht_die_ausnahme_weiter(): void
    {
        $verworfen = 0;
        $uebernommen = 0;

        $writer = new QuarantineFileWriter(
            new ScheiternderEncryptingWriter,
            static function () use (&$verworfen): void {
                $verworfen++;
            },
            static function () use (&$uebernommen): void {
                $uebernommen++;
            },
        );

        $writer->write('abc');

        try {
            $writer->finish();
            self::fail('Die Ausnahme des Writers muss durchgereicht werden.');
        } catch (RuntimeException $exception) {
            self::assertSame('Endblock fehlgeschlagen.', $exception->getMessage());
        }

        self::assertSame(1, $verworfen, 'Die Zwischendatei muss entfernt werden.');
        self::assertSame(0, $uebernommen, 'Ohne Endblock darf nichts auf den Zielpfad verschoben werden.');

        // Ein nachgelagerter Abbruch, etwa aus einem finally-Zweig, ist folgenlos.
        $writer->abort();
        self::assertSame(1, $verworfen);
    }

    public function test_fehler_beim_verschieben_verwirft_die_zwischendatei(): void
    {
        $verworfen = 0;

        $writer = new QuarantineFileWriter(
            new ErfolgreicherEncryptingWriter,
            static function () use (&$verworfen): void {
                $verworfen++;
            },
            static function (): void {
                throw new RuntimeException('Verschieben fehlgeschlagen.');
            },
        );

        try {
            $writer->finish();
            self::fail('Die Ausnahme des Abschlusses muss durchgereicht werden.');
        } catch (RuntimeException $exception) {
            self::assertSame('Verschieben fehlgeschlagen.', $exception->getMessage());
        }

        self::assertSame(1, $verworfen);
    }
}

final class ScheiternderEncryptingWriter implements EncryptingWriter
{
    public function write(string $plaintext): void {}

    public function finish(): int
    {
        throw new RuntimeException('Endblock fehlgeschlagen.');
    }

    public function abort(): void {}
}

final class ErfolgreicherEncryptingWriter implements EncryptingWriter
{
    public function write(string $plaintext): void {}

    public function finish(): int
    {
        return 0;
    }

    public function abort(): void {}
}
