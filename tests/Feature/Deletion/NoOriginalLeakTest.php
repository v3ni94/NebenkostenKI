<?php

declare(strict_types=1);

namespace Tests\Feature\Deletion;

use App\Models\Document;
use App\Models\ProcessingJob;
use App\Models\SourceDeletionEvent;
use App\Models\TemporaryUpload;
use App\Services\Storage\TemporaryUploadStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Upload\Concerns\BuildsUploadWorld;
use Tests\TestCase;
use Tests\Unit\Storage\SampleFiles;

/**
 * Nachweise aus Abschnitt 23.3 und 24:
 *
 * - Originaldateien gelangen weder auf die Artefakt-Disk noch in Queue-Payloads
 *   noch in Logs.
 * - Dauerhaft verbleiben nur die ausdruecklich erlaubten Felder plus
 *   Loeschmetadaten.
 */
class NoOriginalLeakTest extends TestCase
{
    use BuildsUploadWorld, RefreshDatabase;

    /**
     * Erkennungsmerkmal, das in keinem Protokoll und keinem Payload stehen darf.
     */
    private const MARKER = 'GEHEIMER-BELEGINHALT-4711';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpUploadWorld();

        config([
            'smartabrechnen.uploads.max_file_mb' => 25,
            'smartabrechnen.uploads.max_run_mb' => 250,
            'smartabrechnen.uploads.chunk_size_mb' => 1,
        ]);
    }

    public function test_originaldatei_gelangt_niemals_auf_die_artefakt_disk(): void
    {
        $this->bindeErfolgreicheKiSchicht();

        $this->ladeDateiHoch($this->markiertesPdf(), 'pdf');
        $this->verarbeiteQueue();

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('local')->allDirectories());
    }

    public function test_queue_payload_enthaelt_nur_referenz_ids(): void
    {
        $this->ladeDateiHoch($this->markiertesPdf(), 'pdf');

        $payloads = ProcessingJob::query()->pluck('payload')->all();

        $this->assertNotSame([], $payloads);

        foreach ($payloads as $payload) {
            $this->assertIsArray($payload);

            foreach ($payload as $schluessel => $wert) {
                $this->assertIsString($schluessel);
                $this->assertTrue(
                    $wert === null || is_scalar($wert),
                    'Ein Payload darf nur skalare technische Parameter enthalten.'
                );

                if (is_string($wert)) {
                    $this->assertStringNotContainsString(self::MARKER, $wert);
                    $this->assertLessThanOrEqual(128, strlen($wert));
                }
            }
        }

        $roh = (string) json_encode(ProcessingJob::query()->get()->toArray(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString(self::MARKER, $roh);
        $this->assertStringNotContainsString('quarantaene/', $roh);
        $this->assertStringNotContainsString('%PDF', $roh);
    }

    public function test_kein_dateiinhalt_in_den_logs(): void
    {
        Log::spy();

        $this->ladeDateiHoch($this->markiertesPdf(), 'pdf');
        $this->verarbeiteQueue();

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('critical');

        $logdatei = storage_path('logs/laravel.log');

        if (is_file($logdatei)) {
            $inhalt = (string) file_get_contents($logdatei);

            $this->assertStringNotContainsString(self::MARKER, $inhalt);
        }
    }

    public function test_dauerhaft_verbleiben_nur_die_erlaubten_felder(): void
    {
        $this->bindeErfolgreicheKiSchicht();

        $this->ladeDateiHoch($this->markiertesPdf(), 'pdf');
        $this->verarbeiteQueue();

        $dokument = Document::query()->firstOrFail();

        // Alles, was dauerhaft bleibt, ist Metadatum oder Loeschnachweis.
        $erlaubt = [
            'id', 'organization_id', 'billing_run_id', 'sequence_number', 'source_label',
            'document_type', 'document_type_confidence', 'type_assigned_manually',
            'mime_type', 'original_byte_size', 'page_count', 'fingerprint_hmac',
            'processing_status', 'security_checked_at', 'malware_scanner_driver',
            'malware_scan_clean', 'classified_at', 'extracted_at',
            'original_deleted_at', 'deletion_status', 'duplicate_of_document_id',
            'failure_code', 'failure_message', 'created_at', 'updated_at',
        ];

        $this->assertSame([], array_diff(array_keys($dokument->getAttributes()), $erlaubt));

        $gespeichert = (string) json_encode($dokument->getAttributes(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString(self::MARKER, $gespeichert);
        $this->assertStringNotContainsString('quarantaene/', $gespeichert);
    }

    public function test_kein_dateiinhalt_in_der_gesamten_datenbank(): void
    {
        $this->bindeErfolgreicheKiSchicht();

        $this->ladeDateiHoch($this->markiertesPdf(), 'pdf');
        $this->verarbeiteQueue();

        $tabellen = ['documents', 'temporary_uploads', 'processing_jobs', 'source_deletion_events', 'billing_runs'];

        foreach ($tabellen as $tabelle) {
            $zeilen = DB::table($tabelle)->get()->toArray();
            $inhalt = (string) json_encode($zeilen, JSON_THROW_ON_ERROR);

            $this->assertStringNotContainsString(self::MARKER, $inhalt, 'Tabelle '.$tabelle);
            $this->assertStringNotContainsString('%PDF-', $inhalt, 'Tabelle '.$tabelle);
        }
    }

    public function test_der_kurzzeitbereich_ist_nach_abschluss_leer(): void
    {
        $this->bindeErfolgreicheKiSchicht();

        $this->ladeDateiHoch($this->markiertesPdf(), 'pdf');
        $this->verarbeiteQueue();

        $this->assertSame([], Storage::disk(TemporaryUploadStorage::DISK)->allFiles('quarantaene'));
    }

    public function test_tombstone_enthaelt_keinen_schluessel_und_keine_provider_id(): void
    {
        $this->bindeErfolgreicheKiSchicht();

        $this->ladeDateiHoch($this->markiertesPdf(), 'pdf');
        $this->verarbeiteQueue();

        $upload = TemporaryUpload::query()->firstOrFail();

        $this->assertTrue($upload->getAttribute('is_tombstone'));
        $this->assertNull($upload->getAttribute('storage_key'));
        $this->assertNull($upload->getAttribute('provider_file_id'));
        $this->assertNull($upload->getAttribute('last_error'));
    }

    public function test_loeschnachweis_ist_ohne_dateibezug(): void
    {
        $this->bindeErfolgreicheKiSchicht();

        $this->ladeDateiHoch($this->markiertesPdf(), 'pdf');
        $this->verarbeiteQueue();

        foreach (SourceDeletionEvent::query()->get() as $ereignis) {
            $inhalt = (string) json_encode($ereignis->getAttributes(), JSON_THROW_ON_ERROR);

            $this->assertStringNotContainsString(self::MARKER, $inhalt);
            $this->assertStringNotContainsString('quarantaene', $inhalt);
            $this->assertStringNotContainsString('.pdf', $inhalt);
        }
    }

    /**
     * Ein PDF mit einem eindeutigen Erkennungsmerkmal im Inhalt.
     */
    private function markiertesPdf(): string
    {
        return str_replace('trailer', '% '.self::MARKER."\ntrailer", SampleFiles::pdf(2));
    }
}
