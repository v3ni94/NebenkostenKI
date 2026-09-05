<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Models\TemporaryUpload;
use App\Services\Storage\Crypto\CipherIntegrityException;
use App\Services\Storage\Crypto\SodiumSecretstreamCipher;
use App\Services\Storage\Crypto\TemporaryUploadKeyring;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Prueft das Schluesselmodell des Kurzzeitbereichs: ein zufaelliger
 * Dateischluessel je Upload, umhuellt mit einem aus APP_KEY abgeleiteten
 * Hauptschluessel, gespeichert in temporary_uploads.encryption_key_wrapped.
 */
class TemporaryUploadKeyringTest extends TestCase
{
    use RefreshDatabase;

    private TemporaryUploadKeyring $keyring;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);

        TemporaryUploadKeyring::flushProcessCache();

        $this->keyring = new TemporaryUploadKeyring(new SodiumSecretstreamCipher);
    }

    public function test_jedes_praefix_erhaelt_einen_eigenen_zufaelligen_schluessel(): void
    {
        $this->keyring->create('quarantaene/a');
        $this->keyring->create('quarantaene/b');

        $a = $this->keyring->fileKeyForReading('quarantaene/a');
        $b = $this->keyring->fileKeyForReading('quarantaene/b');

        $this->assertSame(32, strlen($a));
        $this->assertNotSame($a, $b);
    }

    public function test_umhuellter_schluessel_enthaelt_den_klartextschluessel_nicht(): void
    {
        $this->keyring->create('quarantaene/a');

        $klar = $this->keyring->fileKeyForReading('quarantaene/a');
        $umhuellt = $this->keyring->wrappedKeyFor('quarantaene/a');

        $this->assertStringStartsWith('s1.', $umhuellt);
        $this->assertStringNotContainsString(base64_encode($klar), $umhuellt);
        $this->assertStringNotContainsString(bin2hex($klar), $umhuellt);
        $this->assertFalse(str_contains($umhuellt, $klar));
    }

    public function test_unbekanntes_praefix_wird_beim_lesen_strikt_abgelehnt(): void
    {
        $this->expectException(CipherIntegrityException::class);

        $this->keyring->fileKeyForReading('quarantaene/unbekannt');
    }

    public function test_schluessel_wird_aus_der_datenbank_wiederhergestellt(): void
    {
        $upload = TemporaryUpload::factory()->create();
        $prefix = (string) $upload->getAttribute('storage_key');

        $this->keyring->create($prefix);
        $klar = $this->keyring->fileKeyForReading($prefix);

        $upload->forceFill(['encryption_key_wrapped' => $this->keyring->wrappedKeyFor($prefix)])->save();

        // Neuer Prozess: kein Cache mehr.
        TemporaryUploadKeyring::flushProcessCache();

        $this->assertSame($klar, (new TemporaryUploadKeyring(new SodiumSecretstreamCipher))->fileKeyForReading($prefix));
    }

    public function test_wechsel_von_app_key_macht_gespeicherte_schluessel_unbrauchbar(): void
    {
        $upload = TemporaryUpload::factory()->create();
        $prefix = (string) $upload->getAttribute('storage_key');

        $this->keyring->create($prefix);
        $upload->forceFill(['encryption_key_wrapped' => $this->keyring->wrappedKeyFor($prefix)])->save();

        TemporaryUploadKeyring::flushProcessCache();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('z', 32))]);

        $this->expectException(CipherIntegrityException::class);

        (new TemporaryUploadKeyring(new SodiumSecretstreamCipher))->fileKeyForReading($prefix);
    }

    public function test_datensatz_ohne_schluessel_erhaelt_beim_schreiben_einen(): void
    {
        $upload = TemporaryUpload::factory()->create(['encryption_key_wrapped' => null]);
        $prefix = (string) $upload->getAttribute('storage_key');

        $klar = $this->keyring->fileKeyForWriting($prefix);

        $upload->refresh();

        $this->assertNotNull($upload->getAttribute('encryption_key_wrapped'));

        TemporaryUploadKeyring::flushProcessCache();

        $this->assertSame($klar, $this->keyring->fileKeyForReading($prefix));
    }

    public function test_tombstone_liefert_keinen_schluessel_mehr(): void
    {
        $upload = TemporaryUpload::factory()->create();
        $prefix = (string) $upload->getAttribute('storage_key');

        $this->keyring->create($prefix);
        $upload->forceFill([
            'encryption_key_wrapped' => $this->keyring->wrappedKeyFor($prefix),
            'is_tombstone' => true,
        ])->save();

        TemporaryUploadKeyring::flushProcessCache();

        $this->expectException(CipherIntegrityException::class);

        $this->keyring->fileKeyForReading($prefix);
    }

    public function test_datenbankfehler_beim_laden_des_schluessels_wird_beim_schreiben_nicht_verschluckt(): void
    {
        // Ohne erreichbare Datenbank darf kein Zufallsschluessel entstehen, der
        // nirgends gespeichert wird: der Chunk waere fuer jeden anderen Prozess
        // unlesbar. Fail closed heisst hier: der Fehler erreicht den Aufrufer.
        Schema::drop('temporary_uploads');

        $this->expectException(QueryException::class);

        $this->keyring->fileKeyForWriting('quarantaene/ohne-datenbank');
    }

    public function test_datenbankfehler_beim_laden_des_schluessels_wird_beim_lesen_nicht_verschluckt(): void
    {
        Schema::drop('temporary_uploads');

        $this->expectException(QueryException::class);

        $this->keyring->fileKeyForReading('quarantaene/ohne-datenbank');
    }

    public function test_vergessen_entfernt_den_schluessel_aus_dem_prozess(): void
    {
        $this->keyring->create('quarantaene/a');
        $this->keyring->forget('quarantaene/a');

        $this->expectException(CipherIntegrityException::class);

        $this->keyring->fileKeyForReading('quarantaene/a');
    }
}
