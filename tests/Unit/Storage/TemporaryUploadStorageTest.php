<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Services\Storage\TemporaryFileKind;
use App\Services\Storage\TemporaryUploadStorage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Prueft die Eigenschaften des Kurzzeitbereichs: zufaellige Pfade, keine
 * Originaldateinamen und vollstaendige Loeschung aller Ableitungen.
 */
class TemporaryUploadStorageTest extends TestCase
{
    private TemporaryUploadStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(TemporaryUploadStorage::DISK);

        $this->storage = new TemporaryUploadStorage;
    }

    public function test_praefix_ist_zufaellig_und_enthaelt_keinen_dateinamen(): void
    {
        $first = $this->storage->newPrefix();
        $second = $this->storage->newPrefix();

        $this->assertNotSame($first, $second);
        $this->assertStringStartsWith('quarantaene/', $first);
        $this->assertMatchesRegularExpression('#^quarantaene/[A-Za-z0-9]{40,}$#', $first);
    }

    public function test_ableitungsname_wird_bereinigt_und_enthaelt_keinen_pfad(): void
    {
        $prefix = $this->storage->newPrefix();

        $key = $this->storage->derivativeKey($prefix, TemporaryFileKind::SEITENBILD, '../../etc/passwd');

        $this->assertStringStartsWith($prefix.'/seitenbilder/', $key);
        $this->assertStringNotContainsString('..', $key);
        $this->assertStringNotContainsString('etc/passwd', $key);
    }

    public function test_loeschung_entfernt_original_seitenbilder_konvertierungen_und_ocr_text(): void
    {
        $prefix = $this->storage->newPrefix();
        $disk = Storage::disk(TemporaryUploadStorage::DISK);

        $disk->put($this->storage->originalKey($prefix), SampleFiles::pdf());
        $this->storage->putDerivative($prefix, TemporaryFileKind::SEITENBILD, 'seite-1.png', SampleFiles::png());
        $this->storage->putDerivative($prefix, TemporaryFileKind::KONVERTIERUNG, 'konvertiert.jpg', SampleFiles::jpeg());
        $this->storage->putDerivative($prefix, TemporaryFileKind::OCR_TEXT, 'volltext.txt', 'Seite 1 Volltext');
        $this->storage->putChunk($prefix, 0, 'abschnitt');

        $this->assertSame(5, $this->storage->countFiles($prefix));

        $this->assertTrue($this->storage->deletePrefix($prefix));
        $this->assertSame(0, $this->storage->countFiles($prefix));
        $this->assertSame([], $disk->allFiles($prefix));
    }

    public function test_loeschung_ist_idempotent(): void
    {
        $prefix = $this->storage->newPrefix();

        Storage::disk(TemporaryUploadStorage::DISK)->put($this->storage->originalKey($prefix), 'x');

        $this->assertTrue($this->storage->deletePrefix($prefix));
        $this->assertTrue($this->storage->deletePrefix($prefix));
    }

    public function test_zaehlt_empfangene_abschnitte_von_der_platte(): void
    {
        $prefix = $this->storage->newPrefix();

        $this->storage->putChunk($prefix, 0, 'AAA');
        $this->storage->putChunk($prefix, 2, 'CCC');

        $this->assertSame([0, 2], $this->storage->receivedChunkIndexes($prefix, 3));
        $this->assertSame([1], $this->storage->missingChunkIndexes($prefix, 3));
        $this->assertSame(6, $this->storage->receivedBytes($prefix, 3));
    }

    public function test_doppelter_abschnitt_wird_nicht_erneut_geschrieben(): void
    {
        $prefix = $this->storage->newPrefix();

        $this->assertTrue($this->storage->putChunk($prefix, 0, 'AAA'));
        $this->assertFalse($this->storage->putChunk($prefix, 0, 'AAA'));
    }
}
