<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Services\Storage\Exceptions\UploadRejectedException;
use App\Services\Storage\FileCategory;
use App\Services\Storage\MimeGuard;
use App\Services\Storage\TemporaryUploadStorage;
use App\Services\Storage\UploadErrorCode;
use App\Services\Storage\UploadLimits;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Prueft die Formaterkennung ueber Magic Bytes, den Abgleich mit der
 * angekuendigten Dateiendung und die Strukturpruefung (Abschnitt 6.3, 19).
 */
class MimeGuardTest extends TestCase
{
    private MimeGuard $guard;

    private UploadLimits $limits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new MimeGuard;
        $this->limits = UploadLimits::of(25 * 1024 * 1024, 250 * 1024 * 1024, 4 * 1024 * 1024);
    }

    public function test_erkennt_pdf_ueber_magic_bytes(): void
    {
        $inspection = $this->guard->inspectFile(
            SampleFiles::write(SampleFiles::pdf(3), 'pdf'),
            'pdf',
            $this->limits
        );

        $this->assertSame('application/pdf', $inspection->mimeType);
        $this->assertSame(FileCategory::PDF, $inspection->category);
        $this->assertSame(3, $inspection->pageCount);
    }

    public function test_erkennt_png_und_jpeg(): void
    {
        $png = $this->guard->inspectFile(SampleFiles::write(SampleFiles::png(), 'png'), 'png', $this->limits);
        $jpeg = $this->guard->inspectFile(SampleFiles::write(SampleFiles::jpeg(), 'jpg'), 'jpg', $this->limits);

        $this->assertSame('image/png', $png->mimeType);
        $this->assertSame('image/jpeg', $jpeg->mimeType);
        $this->assertSame(1, $png->pageCount);
        $this->assertSame(FileCategory::BILD, $jpeg->category);
    }

    public function test_erkennt_heic_als_eigene_kategorie(): void
    {
        $inspection = $this->guard->inspectFile(
            SampleFiles::write(SampleFiles::heic(), 'heic'),
            'heic',
            $this->limits
        );

        $this->assertSame('image/heic', $inspection->mimeType);
        $this->assertTrue($inspection->category->requiresConversion());
    }

    public function test_unterscheidet_xlsx_von_zip(): void
    {
        $xlsx = $this->guard->inspectFile(SampleFiles::write(SampleFiles::xlsx(2), 'xlsx'), 'xlsx', $this->limits);
        $zip = $this->guard->inspectFile(
            SampleFiles::write(SampleFiles::zip(['a.pdf' => SampleFiles::pdf()]), 'zip'),
            'zip',
            $this->limits
        );

        $this->assertSame(FileCategory::TABELLE, $xlsx->category);
        $this->assertSame(2, $xlsx->pageCount);
        $this->assertSame(FileCategory::ARCHIV, $zip->category);
    }

    public function test_erkennt_csv_ohne_magic_bytes(): void
    {
        $inspection = $this->guard->inspectFile(
            SampleFiles::write(SampleFiles::csv(), 'csv'),
            'csv',
            $this->limits
        );

        $this->assertSame('text/csv', $inspection->mimeType);
        $this->assertSame(1, $inspection->pageCount);
    }

    public function test_lehnt_php_inhalt_mit_pdf_endung_ab(): void
    {
        $this->expectException(UploadRejectedException::class);

        try {
            $this->guard->inspectFile(
                SampleFiles::write(SampleFiles::phpDisguisedAsPdf(), 'pdf'),
                'pdf',
                $this->limits
            );
        } catch (UploadRejectedException $exception) {
            // Ausfuehrbarer Inhalt wird noch vor der Formatzuordnung erkannt.
            $this->assertSame(UploadErrorCode::AUSFUEHRBARER_INHALT, $exception->errorCode);
            $this->assertTrue($exception->isPermanent());

            throw $exception;
        }
    }

    public function test_lehnt_unbekanntes_binaerformat_ab(): void
    {
        try {
            $this->guard->inspectFile(
                SampleFiles::write("\x01\x02\x03\x04".str_repeat("\x7f", 200), 'pdf'),
                'pdf',
                $this->limits
            );
            $this->fail('Ein unbekanntes Binaerformat muss abgelehnt werden.');
        } catch (UploadRejectedException $exception) {
            $this->assertSame(UploadErrorCode::MIME_UNBEKANNT, $exception->errorCode);
        }
    }

    public function test_lehnt_umbenanntes_bild_mit_pdf_endung_ab(): void
    {
        try {
            $this->guard->inspectFile(SampleFiles::write(SampleFiles::png(), 'pdf'), 'pdf', $this->limits);
            $this->fail('Eine MIME-Taeuschung muss abgelehnt werden.');
        } catch (UploadRejectedException $exception) {
            $this->assertSame(UploadErrorCode::MIME_TAEUSCHUNG, $exception->errorCode);
        }
    }

    public function test_lehnt_unvollstaendiges_pdf_ab(): void
    {
        try {
            $this->guard->inspectFile(SampleFiles::write('%PDF-1.4 abgeschnitten', 'pdf'), 'pdf', $this->limits);
            $this->fail('Ein PDF ohne Trailer muss abgelehnt werden.');
        } catch (UploadRejectedException $exception) {
            $this->assertSame(UploadErrorCode::STRUKTUR_UNGUELTIG, $exception->errorCode);
        }
    }

    public function test_lehnt_unzulaessige_dateiendung_ab(): void
    {
        try {
            $this->guard->assertExtensionAllowed('exe');
            $this->fail('Eine unzulaessige Endung muss abgelehnt werden.');
        } catch (UploadRejectedException $exception) {
            $this->assertSame(UploadErrorCode::ERWEITERUNG_UNZULAESSIG, $exception->errorCode);
        }
    }

    public function test_lehnt_zu_grosse_datei_ab(): void
    {
        $limits = UploadLimits::of(64, 1024, 32);

        try {
            $this->guard->inspectFile(SampleFiles::write(SampleFiles::pdf(), 'pdf'), 'pdf', $limits);
            $this->fail('Eine zu grosse Datei muss abgelehnt werden.');
        } catch (UploadRejectedException $exception) {
            $this->assertSame(UploadErrorCode::DATEI_ZU_GROSS, $exception->errorCode);
        }
    }

    public function test_lehnt_leere_datei_ab(): void
    {
        try {
            $this->guard->inspectFile(SampleFiles::write('', 'pdf'), 'pdf', $this->limits);
            $this->fail('Eine leere Datei muss abgelehnt werden.');
        } catch (UploadRejectedException $exception) {
            $this->assertSame(UploadErrorCode::DATEI_LEER, $exception->errorCode);
        }
    }

    public function test_prueft_eine_verschluesselte_quelle_ueber_den_klartextstrom(): void
    {
        Storage::fake(TemporaryUploadStorage::DISK);

        $storage = new TemporaryUploadStorage;
        $prefix = $storage->newPrefix();
        $key = $storage->originalKey($prefix);

        $storage->put($key, SampleFiles::pdf(3));

        $inspection = $this->guard->inspectSource($storage->source($key), 'pdf', $this->limits);

        $this->assertSame('application/pdf', $inspection->mimeType);
        $this->assertSame(FileCategory::PDF, $inspection->category);
        $this->assertSame(strlen(SampleFiles::pdf(3)), $inspection->byteSize);
        $this->assertSame(3, $inspection->pageCount);
    }

    public function test_verschluesselte_xlsx_wird_ueber_die_arbeitskopie_gezaehlt_und_hinterlaesst_nichts(): void
    {
        Storage::fake(TemporaryUploadStorage::DISK);

        $storage = new TemporaryUploadStorage;
        $prefix = $storage->newPrefix();
        $key = $storage->originalKey($prefix);

        $storage->put($key, SampleFiles::xlsx(2));

        $inspection = $this->guard->inspectSource($storage->source($key), 'xlsx', $this->limits);

        $this->assertSame(FileCategory::TABELLE, $inspection->category);
        $this->assertSame(2, $inspection->pageCount);
        $this->assertSame([$key], Storage::disk(TemporaryUploadStorage::DISK)->allFiles($prefix));
    }

    public function test_mime_taeuschung_wird_auch_bei_verschluesselter_quelle_erkannt(): void
    {
        Storage::fake(TemporaryUploadStorage::DISK);

        $storage = new TemporaryUploadStorage;
        $key = $storage->originalKey($storage->newPrefix());

        $storage->put($key, SampleFiles::png());

        try {
            $this->guard->inspectSource($storage->source($key), 'pdf', $this->limits);
            $this->fail('Eine MIME-Taeuschung muss abgelehnt werden.');
        } catch (UploadRejectedException $exception) {
            $this->assertSame(UploadErrorCode::MIME_TAEUSCHUNG, $exception->errorCode);
        }
    }

    public function test_liefert_nur_die_endung_und_niemals_den_dateinamen(): void
    {
        $this->assertSame('pdf', $this->guard->extensionOf('Grundsteuerbescheid Familie Mustermann.PDF'));
        $this->assertNull($this->guard->extensionOf('ohne-endung'));
        $this->assertSame(['application/pdf'], $this->guard->mimeTypesForExtension('pdf'));
    }
}
