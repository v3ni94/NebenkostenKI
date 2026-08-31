<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Services\Storage\Exceptions\UploadRejectedException;
use App\Services\Storage\HeicConverter;
use App\Services\Storage\UploadErrorCode;
use Tests\TestCase;

/**
 * Prueft die HEIC-Behandlung.
 *
 * ENTSCHEIDUNG (Abschnitt 6.1): Ohne Imagick mit HEIC-Delegate wird die Datei
 * nicht still abgelehnt. Der Nutzer erhaelt eine klare deutsche
 * Handlungsanweisung, wie er das Foto auf seinem Geraet als JPG speichert.
 */
class HeicConverterTest extends TestCase
{
    public function test_meldet_fehlenden_konverter_mit_deutscher_handlungsanweisung(): void
    {
        $converter = new HeicConverter;

        if ($converter->isAvailable()) {
            $this->markTestSkipped('Auf diesem System ist ein HEIC-Konverter vorhanden.');
        }

        $anweisung = $converter->fallbackInstruction();

        $this->assertStringContainsString('HEIC', $anweisung);
        $this->assertStringContainsString('JPG', $anweisung);
        $this->assertStringContainsString('Maximale Kompatibilität', $anweisung);
    }

    public function test_lehnt_die_umwandlung_ohne_konverter_mit_klarem_fehlercode_ab(): void
    {
        $converter = new HeicConverter;

        if ($converter->isAvailable()) {
            $this->markTestSkipped('Auf diesem System ist ein HEIC-Konverter vorhanden.');
        }

        try {
            $converter->convertToJpeg(
                SampleFiles::write(SampleFiles::heic(), 'heic'),
                SampleFiles::temporaryPath('jpg')
            );
            $this->fail('Ohne Konverter muss die Umwandlung abgelehnt werden.');
        } catch (UploadRejectedException $exception) {
            $this->assertSame(UploadErrorCode::HEIC_KONVERTER_FEHLT, $exception->errorCode);
            $this->assertTrue($exception->isPermanent());
        }
    }

    public function test_die_verfuegbarkeitspruefung_wirft_niemals(): void
    {
        $converter = new HeicConverter;

        $this->assertIsBool($converter->isAvailable());
    }
}
