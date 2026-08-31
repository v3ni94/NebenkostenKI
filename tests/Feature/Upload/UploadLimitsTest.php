<?php

declare(strict_types=1);

namespace Tests\Feature\Upload;

use App\Models\BillingRun;
use App\Models\Document;
use App\Services\Storage\UploadErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Upload\Concerns\BuildsUploadWorld;
use Tests\TestCase;
use Tests\Unit\Storage\SampleFiles;

/**
 * Prueft die Volumengrenzen je Datei und je Abrechnungslauf (Abschnitt 6.1).
 *
 * Standard sind 25 MB je Datei und 250 MB je Lauf, beides per ENV
 * konfigurierbar.
 */
class UploadLimitsTest extends TestCase
{
    use BuildsUploadWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpUploadWorld();
    }

    public function test_ueberschreiten_des_dateilimits_wird_beim_start_abgelehnt(): void
    {
        config(['smartabrechnen.uploads.max_file_mb' => 1]);

        $antwort = $this->starteUpload('unterlage.pdf', 2 * 1024 * 1024);

        $antwort->assertStatus(422);
        $antwort->assertJsonValidationErrors('groesse');
        $this->assertSame(0, Document::query()->count());
    }

    public function test_ueberschreiten_des_laufslimits_wird_abgelehnt(): void
    {
        config([
            'smartabrechnen.uploads.max_file_mb' => 5,
            'smartabrechnen.uploads.max_run_mb' => 6,
            'smartabrechnen.uploads.chunk_size_mb' => 5,
        ]);

        $this->starteUpload('erste.pdf', 4 * 1024 * 1024)->assertCreated();

        $zweite = $this->starteUpload('zweite.pdf', 4 * 1024 * 1024);

        $zweite->assertStatus(422);
        $zweite->assertJsonPath('fehlercode', UploadErrorCode::LAUF_LIMIT_ERREICHT->value);
        $this->assertSame(1, Document::query()->count());
    }

    public function test_offene_uploads_zaehlen_auf_das_laufslimit(): void
    {
        config([
            'smartabrechnen.uploads.max_file_mb' => 5,
            'smartabrechnen.uploads.max_run_mb' => 10,
            'smartabrechnen.uploads.chunk_size_mb' => 5,
        ]);

        $this->starteUpload('a.pdf', 4 * 1024 * 1024)->assertCreated();
        $this->starteUpload('b.pdf', 4 * 1024 * 1024)->assertCreated();

        // Noch kein einziger Abschnitt uebertragen, das Volumen ist dennoch
        // reserviert. Sonst liesse sich das Limit durch viele gleichzeitige
        // Uploads umgehen.
        $this->starteUpload('c.pdf', 4 * 1024 * 1024)
            ->assertStatus(422)
            ->assertJsonPath('fehlercode', UploadErrorCode::LAUF_LIMIT_ERREICHT->value);
    }

    public function test_tatsaechliche_groesse_wird_nach_der_zusammensetzung_verbucht(): void
    {
        config([
            'smartabrechnen.uploads.max_file_mb' => 5,
            'smartabrechnen.uploads.max_run_mb' => 250,
            'smartabrechnen.uploads.chunk_size_mb' => 5,
        ]);

        $inhalt = SampleFiles::pdf(2);

        $this->ladeDateiHoch($inhalt, 'pdf');
        $this->verarbeiteQueue();

        $lauf = BillingRun::query()->findOrFail($this->welt()['billingRun']->getKey());

        $this->assertSame(strlen($inhalt), (int) $lauf->getAttribute('uploaded_bytes'));
    }

    public function test_verbuchung_erfolgt_nicht_doppelt_bei_erneutem_lauf(): void
    {
        config([
            'smartabrechnen.uploads.max_file_mb' => 5,
            'smartabrechnen.uploads.max_run_mb' => 250,
            'smartabrechnen.uploads.chunk_size_mb' => 5,
        ]);

        $inhalt = SampleFiles::pdf(2);

        $this->ladeDateiHoch($inhalt, 'pdf');
        $this->verarbeiteQueue();
        $this->verarbeiteQueue();

        $lauf = BillingRun::query()->findOrFail($this->welt()['billingRun']->getKey());

        $this->assertSame(strlen($inhalt), (int) $lauf->getAttribute('uploaded_bytes'));
    }

    public function test_ueberschreiten_des_limits_waehrend_der_uebertragung_verwirft_den_upload(): void
    {
        config([
            'smartabrechnen.uploads.max_file_mb' => 5,
            'smartabrechnen.uploads.max_run_mb' => 250,
            'smartabrechnen.uploads.chunk_size_mb' => 5,
        ]);

        $antwort = $this->starteUpload('unterlage.pdf', 1024);
        $uploadId = (string) $antwort->json('upload_id');

        // Der Browser sendet mehr als angekuendigt.
        config(['smartabrechnen.uploads.max_file_mb' => 1]);

        $this->sendeAbschnitt($uploadId, 0, str_repeat('A', 2 * 1024 * 1024))
            ->assertStatus(422)
            ->assertJsonPath('fehlercode', UploadErrorCode::DATEI_ZU_GROSS->value);
    }
}
