<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Services\Storage\ArchiveGuard;
use App\Services\Storage\Exceptions\UploadRejectedException;
use App\Services\Storage\UploadErrorCode;
use App\Services\Storage\UploadLimits;
use Tests\TestCase;

/**
 * Prueft die Einzelvalidierung jedes Archiveintrags, den Schutz gegen
 * Zip-Bomben und gegen Path Traversal (Abschnitt 6.1 und 19).
 */
class ArchiveGuardTest extends TestCase
{
    private ArchiveGuard $guard;

    private UploadLimits $limits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new ArchiveGuard;
        $this->limits = UploadLimits::of(25 * 1024 * 1024, 250 * 1024 * 1024, 4 * 1024 * 1024);
    }

    public function test_akzeptiert_archiv_mit_gueltigen_eintraegen(): void
    {
        $path = SampleFiles::write(SampleFiles::zip([
            'bescheid.pdf' => SampleFiles::pdf(2),
            'unterordner/foto.png' => SampleFiles::png(),
            'liste.csv' => SampleFiles::csv(),
        ]), 'zip');

        $entries = $this->guard->inspect($path, $this->limits);

        $this->assertCount(3, $entries);
        $this->assertSame(['pdf', 'png', 'csv'], array_map(fn ($entry): string => $entry->extension, $entries));
    }

    public function test_lehnt_path_traversal_ab(): void
    {
        $path = SampleFiles::write(SampleFiles::zipWithTraversal(), 'zip');

        try {
            $this->guard->inspect($path, $this->limits);
            $this->fail('Ein Archiv mit Path Traversal muss abgelehnt werden.');
        } catch (UploadRejectedException $exception) {
            $this->assertSame(UploadErrorCode::ARCHIV_TRAVERSAL, $exception->errorCode);
        }
    }

    public function test_lehnt_zip_bombe_ueber_das_kompressionsverhaeltnis_ab(): void
    {
        $path = SampleFiles::write(SampleFiles::zipBomb(), 'zip');

        try {
            $this->guard->inspect($path, $this->limits);
            $this->fail('Eine Zip-Bombe muss abgelehnt werden.');
        } catch (UploadRejectedException $exception) {
            $this->assertSame(UploadErrorCode::ARCHIV_ZIP_BOMBE, $exception->errorCode);
        }
    }

    public function test_lehnt_zu_grossen_entpackten_eintrag_ab(): void
    {
        $limits = UploadLimits::of(512, 4096, 256);
        $path = SampleFiles::write(SampleFiles::zip(['gross.pdf' => SampleFiles::pdf(60)]), 'zip');

        try {
            $this->guard->inspect($path, $limits);
            $this->fail('Ein zu grosser Eintrag muss abgelehnt werden.');
        } catch (UploadRejectedException $exception) {
            $this->assertSame(UploadErrorCode::ARCHIV_ZIP_BOMBE, $exception->errorCode);
        }
    }

    public function test_lehnt_verschachteltes_archiv_ab(): void
    {
        $path = SampleFiles::write(SampleFiles::nestedZip(), 'zip');

        try {
            $this->guard->inspect($path, $this->limits);
            $this->fail('Ein verschachteltes Archiv muss abgelehnt werden.');
        } catch (UploadRejectedException $exception) {
            $this->assertSame(UploadErrorCode::ARCHIV_VERSCHACHTELT, $exception->errorCode);
        }
    }

    public function test_lehnt_unzulaessiges_format_im_archiv_ab(): void
    {
        $path = SampleFiles::write(SampleFiles::zip([
            'gut.pdf' => SampleFiles::pdf(),
            'skript.php' => '<?php echo 1;',
        ]), 'zip');

        try {
            $this->guard->inspect($path, $this->limits);
            $this->fail('Ein unzulaessiger Eintrag muss das Archiv ablehnen.');
        } catch (UploadRejectedException $exception) {
            $this->assertSame(UploadErrorCode::ARCHIV_EINTRAG_UNZULAESSIG, $exception->errorCode);
        }
    }

    public function test_lehnt_leeres_archiv_ab(): void
    {
        $path = SampleFiles::write(SampleFiles::zip(['__MACOSX/hinweis' => 'x']), 'zip');

        try {
            $this->guard->inspect($path, $this->limits);
            $this->fail('Ein Archiv ohne auswertbaren Eintrag muss abgelehnt werden.');
        } catch (UploadRejectedException $exception) {
            $this->assertSame(UploadErrorCode::ARCHIV_LEER, $exception->errorCode);
        }
    }
}
