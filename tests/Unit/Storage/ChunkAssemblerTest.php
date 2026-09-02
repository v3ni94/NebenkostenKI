<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Services\Storage\ChunkAssembler;
use App\Services\Storage\Crypto\CipherIntegrityException;
use App\Services\Storage\Exceptions\UploadRejectedException;
use App\Services\Storage\TemporaryUploadStorage;
use App\Services\Storage\UploadErrorCode;
use App\Services\Storage\UploadLimits;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Prueft die serverseitige Wiederzusammensetzung des Chunk-Uploads.
 *
 * Es wird ausschliesslich gegen Storage::fake gearbeitet, niemals gegen einen
 * echten SFTP-Server.
 */
class ChunkAssemblerTest extends TestCase
{
    private TemporaryUploadStorage $storage;

    private ChunkAssembler $assembler;

    private UploadLimits $limits;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(TemporaryUploadStorage::DISK);

        $this->storage = new TemporaryUploadStorage;
        $this->assembler = new ChunkAssembler($this->storage);
        $this->limits = UploadLimits::of(25 * 1024 * 1024, 250 * 1024 * 1024, 8);
    }

    public function test_setzt_abschnitte_in_der_richtigen_reihenfolge_zusammen(): void
    {
        $prefix = $this->storage->newPrefix();

        $this->storage->putChunk($prefix, 0, 'AAAA');
        $this->storage->putChunk($prefix, 1, 'BBBB');
        $this->storage->putChunk($prefix, 2, 'CC');

        $result = $this->assembler->assemble($prefix, 3, 10, $this->limits);

        $this->assertFalse($result->alreadyAssembled);
        $this->assertSame(10, $result->byteSize);
        $this->assertSame('AAAABBBBCC', $this->storage->read($result->storageKey));
    }

    public function test_zusammengesetzte_datei_liegt_verschluesselt_auf_der_platte(): void
    {
        $prefix = $this->storage->newPrefix();
        $marker = 'GEHEIMER-ABSCHNITT-2024';

        $this->storage->putChunk($prefix, 0, $marker.'-erster-teil');
        $this->storage->putChunk($prefix, 1, 'zweiter-teil-'.$marker);

        $result = $this->assembler->assemble($prefix, 2, 0, $this->limits);

        $chiffrat = (string) Storage::disk(TemporaryUploadStorage::DISK)->get($result->storageKey);

        $this->assertStringNotContainsString($marker, $chiffrat);
        $this->assertStringStartsWith('SAQ1', $chiffrat);
        $this->assertSame($marker.'-erster-teilzweiter-teil-'.$marker, $this->storage->read($result->storageKey));
        $this->assertSame($result->byteSize, $this->storage->size($result->storageKey));
    }

    public function test_manipulierter_abschnitt_wird_bei_der_zusammensetzung_abgelehnt(): void
    {
        $prefix = $this->storage->newPrefix();

        $this->storage->putChunk($prefix, 0, str_repeat('A', 512));

        $pfad = $this->storage->absolutePath($this->storage->chunkKey($prefix, 0));
        $chiffrat = (string) file_get_contents($pfad);
        $chiffrat[60] = chr(ord($chiffrat[60]) ^ 0x01);
        file_put_contents($pfad, $chiffrat);

        try {
            $this->assembler->assemble($prefix, 1, 512, $this->limits);
            $this->fail('Ein manipulierter Abschnitt darf nicht zusammengesetzt werden.');
        } catch (CipherIntegrityException) {
            $this->assertFalse(
                $this->storage->exists($this->storage->originalKey($prefix)),
                'Nach dem Abbruch darf keine unvollstaendige Zieldatei liegen bleiben.'
            );
        }
    }

    public function test_entfernt_die_abschnitte_nach_der_zusammensetzung(): void
    {
        $prefix = $this->storage->newPrefix();

        $this->storage->putChunk($prefix, 0, 'AAAA');
        $this->storage->putChunk($prefix, 1, 'BB');

        $this->assembler->assemble($prefix, 2, 6, $this->limits);

        $this->assertFalse($this->storage->hasChunk($prefix, 0));
        $this->assertFalse($this->storage->hasChunk($prefix, 1));
    }

    public function test_meldet_fehlenden_abschnitt(): void
    {
        $prefix = $this->storage->newPrefix();

        $this->storage->putChunk($prefix, 0, 'AAAA');
        $this->storage->putChunk($prefix, 2, 'CCCC');

        try {
            $this->assembler->assemble($prefix, 3, 12, $this->limits);
            $this->fail('Ein fehlender Abschnitt muss gemeldet werden.');
        } catch (UploadRejectedException $exception) {
            $this->assertSame(UploadErrorCode::CHUNK_FEHLT, $exception->errorCode);
            $this->assertSame('1', $exception->technicalContext()['fehlende_abschnitte'] ?? null);
        }
    }

    public function test_ist_idempotent_bei_erneutem_aufruf(): void
    {
        $prefix = $this->storage->newPrefix();

        $this->storage->putChunk($prefix, 0, 'AAAABBBB');

        $first = $this->assembler->assemble($prefix, 1, 8, $this->limits);
        $second = $this->assembler->assemble($prefix, 1, 8, $this->limits);

        $this->assertFalse($first->alreadyAssembled);
        $this->assertTrue($second->alreadyAssembled);
        $this->assertSame($first->byteSize, $second->byteSize);
    }

    public function test_lehnt_ueberschreitung_des_dateilimits_ab(): void
    {
        $limits = UploadLimits::of(6, 1024, 4);
        $prefix = $this->storage->newPrefix();

        $this->storage->putChunk($prefix, 0, 'AAAA');
        $this->storage->putChunk($prefix, 1, 'BBBB');

        try {
            $this->assembler->assemble($prefix, 2, 8, $limits);
            $this->fail('Das Dateilimit muss auch beim Zusammensetzen greifen.');
        } catch (UploadRejectedException $exception) {
            $this->assertSame(UploadErrorCode::DATEI_ZU_GROSS, $exception->errorCode);
            $this->assertFalse($this->storage->exists($this->storage->originalKey($prefix)));
        }
    }

    public function test_verwirft_unvollstaendiges_ergebnis_bei_abweichender_groesse(): void
    {
        $prefix = $this->storage->newPrefix();

        $this->storage->putChunk($prefix, 0, 'AAAA');

        try {
            $this->assembler->assemble($prefix, 1, 99, $this->limits);
            $this->fail('Eine abweichende Gesamtgroesse muss zu einer Ablehnung fuehren.');
        } catch (UploadRejectedException $exception) {
            $this->assertSame(UploadErrorCode::CHUNK_FEHLT, $exception->errorCode);
            $this->assertFalse($this->storage->exists($this->storage->originalKey($prefix)));
        }
    }
}
