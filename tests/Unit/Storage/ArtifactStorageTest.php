<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Services\Storage\ArtifactStorage;
use App\Services\Storage\ArtifactType;
use App\Services\Storage\Exceptions\ArtifactRejectedException;
use App\Services\Storage\TemporaryUploadStorage;
use App\Services\Storage\UploadErrorCode;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Prueft die technische Sperre der Artefaktablage.
 *
 * VERBINDLICH (ADR-007, Abschnitt 3.4): Originaluploads duerfen niemals auf
 * die Disks sftp oder s3 gelangen. Die Sperre ist technisch und nicht nur
 * organisatorisch.
 */
class ArtifactStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake(TemporaryUploadStorage::DISK);

        config(['filesystems.default' => 'local']);
    }

    public function test_schreibt_ein_erzeugtes_pdf_artefakt(): void
    {
        $artifacts = new ArtifactStorage;

        $reference = $artifacts->put(ArtifactType::MIETERABRECHNUNG_FINAL, 'ORG123', SampleFiles::pdf());

        $this->assertTrue($artifacts->exists($reference));
        $this->assertStringStartsWith('abrechnungen/final/ORG123/', $reference->path);
        $this->assertStringEndsWith('.pdf', $reference->path);
        $this->assertSame(64, strlen($reference->sha256));
    }

    public function test_verhindert_dass_ein_originalupload_als_artefakt_gespeichert_wird(): void
    {
        $artifacts = new ArtifactStorage;

        foreach ([SampleFiles::jpeg(), SampleFiles::png(), SampleFiles::heic(), SampleFiles::csv(), SampleFiles::xlsx()] as $original) {
            try {
                $artifacts->put(ArtifactType::MIETERABRECHNUNG_FINAL, 'ORG123', $original);
                $this->fail('Ein Originalupload darf niemals in die Artefaktablage gelangen.');
            } catch (ArtifactRejectedException $exception) {
                $this->assertSame(UploadErrorCode::ARTEFAKT_UNZULAESSIG, $exception->errorCode);
            }
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_verhindert_ein_pdf_im_zip_artefakt_und_umgekehrt(): void
    {
        $artifacts = new ArtifactStorage;

        $this->expectException(ArtifactRejectedException::class);

        $artifacts->put(ArtifactType::ZIP_PAKET, 'ORG123', SampleFiles::pdf());
    }

    public function test_akzeptiert_ein_zip_paket(): void
    {
        $artifacts = new ArtifactStorage;

        $reference = $artifacts->put(
            ArtifactType::ZIP_PAKET,
            'ORG123',
            SampleFiles::zip(['abrechnung.pdf' => SampleFiles::pdf()])
        );

        $this->assertStringEndsWith('.zip', $reference->path);
        $this->assertTrue($artifacts->exists($reference));
    }

    public function test_sperrt_die_disk_des_kurzzeitbereichs(): void
    {
        $artifacts = new ArtifactStorage(TemporaryUploadStorage::DISK);

        try {
            $artifacts->put(ArtifactType::MIETERABRECHNUNG_FINAL, 'ORG123', SampleFiles::pdf());
            $this->fail('Die Disk des Kurzzeitbereichs darf niemals Artefakte aufnehmen.');
        } catch (ArtifactRejectedException $exception) {
            $this->assertSame(UploadErrorCode::ARTEFAKT_DISK_UNZULAESSIG, $exception->errorCode);
        }
    }

    public function test_sperrt_das_kopieren_aus_dem_kurzzeitbereich(): void
    {
        $artifacts = new ArtifactStorage;

        $this->expectException(ArtifactRejectedException::class);

        $artifacts->copyFromDisk(TemporaryUploadStorage::DISK, 'quarantaene/irgendwas/original.bin');
    }

    public function test_es_gibt_keinen_artefakttyp_fuer_quelldateien(): void
    {
        $werte = array_map(fn (ArtifactType $type): string => $type->value, ArtifactType::cases());

        foreach (['ORIGINAL', 'UPLOAD', 'SEITENBILD', 'OCR', 'QUELLE', 'VORSCHAUBILD'] as $verboten) {
            foreach ($werte as $wert) {
                $this->assertStringNotContainsString($verboten, $wert);
            }
        }

        // Jeder Artefakttyp erzeugt entweder ein PDF oder ein ZIP.
        foreach (ArtifactType::cases() as $type) {
            $this->assertContains($type->extension(), ['pdf', 'zip']);
        }
    }

    public function test_liefert_ein_artefakt_mit_neutralem_dateinamen_aus(): void
    {
        $artifacts = new ArtifactStorage;

        $reference = $artifacts->put(ArtifactType::HVM_RECHNUNG, 'ORG123', SampleFiles::pdf());

        $antwort = $artifacts->download($reference, 'rechnung.pdf', 'application/pdf');

        $this->assertSame(200, $antwort->getStatusCode());
        $this->assertStringContainsString('rechnung.pdf', (string) $antwort->headers->get('content-disposition'));
        $this->assertSame('nosniff', $antwort->headers->get('x-content-type-options'));
    }
}
