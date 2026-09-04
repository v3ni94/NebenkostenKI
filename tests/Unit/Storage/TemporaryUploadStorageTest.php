<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Services\Storage\Crypto\CipherIntegrityException;
use App\Services\Storage\Crypto\SodiumSecretstreamCipher;
use App\Services\Storage\Crypto\TemporaryUploadKeyring;
use App\Services\Storage\TemporaryFileKind;
use App\Services\Storage\TemporaryUploadStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Prueft die Eigenschaften des Kurzzeitbereichs: zufaellige Pfade, keine
 * Originaldateinamen, vollstaendige Loeschung aller Ableitungen und
 * Verschluesselung jeder Datei auf der Platte.
 *
 * Die Datenbank wird benoetigt, weil der Schluesselbund einen fehlenden
 * Prozessschluessel in temporary_uploads nachschlaegt und einen
 * Datenbankfehler dabei bewusst nicht verschluckt.
 */
class TemporaryUploadStorageTest extends TestCase
{
    use RefreshDatabase;

    private const MARKER = 'VERTRAULICHER-INHALT-4711';

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

        $this->storage->put($this->storage->originalKey($prefix), SampleFiles::pdf());
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

    public function test_abschnitt_liegt_nur_verschluesselt_auf_der_platte(): void
    {
        $prefix = $this->storage->newPrefix();

        $this->storage->putChunk($prefix, 0, self::MARKER.SampleFiles::pdf());

        $key = $this->storage->chunkKey($prefix, 0);
        $aufPlatte = (string) Storage::disk(TemporaryUploadStorage::DISK)->get($key);

        $this->assertStringNotContainsString(self::MARKER, $aufPlatte);
        $this->assertStringNotContainsString('%PDF', $aufPlatte);
        $this->assertStringStartsWith('SAQ1', $aufPlatte);
        $this->assertSame(strlen(self::MARKER.SampleFiles::pdf()), $this->storage->size($key));
        $this->assertSame(self::MARKER.SampleFiles::pdf(), $this->storage->read($key));
    }

    public function test_roundtrip_ueber_strom_oberhalb_der_blockgroesse_und_leere_datei(): void
    {
        $prefix = $this->storage->newPrefix();
        $gross = random_bytes(3 * 1024 * 1024 + 99);

        $quelle = fopen('php://memory', 'w+b');
        $this->assertIsResource($quelle);
        fwrite($quelle, $gross);
        rewind($quelle);

        $grossKey = $this->storage->derivativeKey($prefix, TemporaryFileKind::ARCHIV_EINTRAG, 'gross.bin');
        $this->assertSame(strlen($gross), $this->storage->putStream($grossKey, $quelle));
        fclose($quelle);

        $strom = $this->storage->readStream($grossKey);
        $gelesen = stream_get_contents($strom);
        fclose($strom);

        $this->assertSame(hash('sha256', $gross), hash('sha256', (string) $gelesen));

        $leerKey = $this->storage->derivativeKey($prefix, TemporaryFileKind::OCR_TEXT, 'leer.txt');
        $this->storage->put($leerKey, '');

        $this->assertTrue($this->storage->exists($leerKey));
        $this->assertSame(0, $this->storage->size($leerKey));
        $this->assertSame('', $this->storage->read($leerKey));
    }

    public function test_manipuliertes_chiffrat_wird_beim_lesen_abgelehnt(): void
    {
        $prefix = $this->storage->newPrefix();
        $key = $this->storage->originalKey($prefix);

        $this->storage->put($key, SampleFiles::pdf(2));

        $pfad = $this->storage->absolutePath($key);
        $chiffrat = (string) file_get_contents($pfad);
        $chiffrat[50] = chr(ord($chiffrat[50]) ^ 0x80);
        file_put_contents($pfad, $chiffrat);

        $this->expectException(CipherIntegrityException::class);

        $this->storage->read($key);
    }

    public function test_chiffrat_eines_uploads_ist_mit_dem_schluessel_eines_anderen_nicht_lesbar(): void
    {
        $quelle = $this->storage->newPrefix();
        $fremd = $this->storage->newPrefix();

        $this->storage->putChunk($quelle, 0, SampleFiles::pdf(2));

        // Das Chiffrat wird unter dem Praefix des anderen Uploads abgelegt und
        // damit mit dessen Schluessel gelesen.
        Storage::disk(TemporaryUploadStorage::DISK)->copy(
            $this->storage->chunkKey($quelle, 0),
            $this->storage->chunkKey($fremd, 0),
        );

        $this->expectException(CipherIntegrityException::class);

        $this->storage->read($this->storage->chunkKey($fremd, 0));
    }

    public function test_arbeitskopie_existiert_nur_waehrend_des_aufrufs(): void
    {
        $prefix = $this->storage->newPrefix();
        $key = $this->storage->originalKey($prefix);
        $inhalt = self::MARKER.SampleFiles::pdf(2);

        $this->storage->put($key, $inhalt);

        $gesehen = null;

        $ergebnis = $this->storage->withDecryptedCopy($key, function (string $pfad) use (&$gesehen): int {
            $gesehen = (string) file_get_contents($pfad);

            $this->assertStringStartsWith(
                Storage::disk(TemporaryUploadStorage::DISK)->path(''),
                $pfad,
                'Die Arbeitskopie muss innerhalb des Kurzzeitbereichs liegen.'
            );

            return 42;
        });

        $this->assertSame(42, $ergebnis);
        $this->assertSame($inhalt, $gesehen);
        $this->assertSame([$key], Storage::disk(TemporaryUploadStorage::DISK)->allFiles($prefix));

        foreach (Storage::disk(TemporaryUploadStorage::DISK)->allFiles() as $datei) {
            $this->assertStringNotContainsString(self::MARKER, (string) Storage::disk(TemporaryUploadStorage::DISK)->get($datei));
        }
    }

    public function test_arbeitskopie_wird_auch_bei_ausnahme_entfernt(): void
    {
        $prefix = $this->storage->newPrefix();
        $key = $this->storage->originalKey($prefix);

        $this->storage->put($key, self::MARKER);

        try {
            $this->storage->withDecryptedCopy($key, static function (string $pfad): int {
                if (is_file($pfad)) {
                    throw new RuntimeException('Pruefung abgebrochen.');
                }

                return 0;
            });
            $this->fail('Die Ausnahme muss durchgereicht werden.');
        } catch (RuntimeException) {
            $this->assertSame([$key], Storage::disk(TemporaryUploadStorage::DISK)->allFiles($prefix));
        }
    }

    public function test_gleichzeitige_schreibvorgaenge_auf_denselben_abschnitt_erzeugen_kein_korruptes_chiffrat(): void
    {
        $prefix = $this->storage->newPrefix();
        $key = $this->storage->chunkKey($prefix, 7);

        // Zwei Requests fuer denselben Abschnitt, etwa eine Wiederholung des
        // Browsers nach Zeitueberschreitung, waehrend der erste noch schreibt.
        $erster = $this->storage->openWriter($key);
        $zweiter = $this->storage->openWriter($key);

        $this->assertFalse($this->storage->exists($key), 'Vor dem Abschluss darf keine Zieldatei unter dem endgueltigen Pfad liegen.');

        $erster->write(str_repeat('A', 3000));
        $zweiter->write(str_repeat('B', 3000));

        $erster->finish();
        $zweiter->finish();

        $this->assertSame(str_repeat('B', 3000), $this->storage->read($key), 'Der zuletzt abgeschlossene Vorgang gewinnt vollstaendig, kein Mischchiffrat.');
        $this->assertSame([$key], Storage::disk(TemporaryUploadStorage::DISK)->allFiles($prefix), 'Es bleibt keine Zwischendatei liegen.');
    }

    public function test_abgebrochener_schreibvorgang_hinterlaesst_keine_datei(): void
    {
        $prefix = $this->storage->newPrefix();
        $key = $this->storage->chunkKey($prefix, 0);

        $writer = $this->storage->openWriter($key);
        $writer->write('unvollstaendig');
        $writer->abort();

        $this->assertFalse($this->storage->exists($key));
        $this->assertSame([], Storage::disk(TemporaryUploadStorage::DISK)->allFiles($prefix));
    }

    public function test_arbeitskopie_wird_bei_vollem_datentraeger_nicht_abgeschnitten_an_die_pruefung_gegeben(): void
    {
        if (! file_exists('/dev/full') || ! function_exists('symlink')) {
            $this->markTestSkipped('Der Test benoetigt /dev/full, um einen vollen Datentraeger nachzustellen.');
        }

        $prefix = $this->storage->newPrefix();
        $key = $this->storage->originalKey($prefix);

        $this->storage->put($key, SampleFiles::pdf(2));

        // Der Name der Arbeitskopie wird festgelegt, damit sie vorab als
        // Verweis auf /dev/full angelegt werden kann: jeder Schreibversuch
        // scheitert dort mit "kein Speicherplatz".
        $arbeitsverzeichnis = Storage::disk(TemporaryUploadStorage::DISK)->path($prefix.'/arbeit');
        mkdir($arbeitsverzeichnis, 0700, true);

        $this->assertTrue(symlink('/dev/full', $arbeitsverzeichnis.'/vollerdatentraeger.tmp'));

        Str::createRandomStringsUsing(static fn (): string => 'vollerdatentraeger');

        $aufgerufen = false;

        try {
            $this->storage->withDecryptedCopy($key, static function () use (&$aufgerufen): int {
                $aufgerufen = true;

                return 0;
            });
            $this->fail('Eine unvollstaendige Arbeitskopie darf nicht an die Pruefung gegeben werden.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Arbeitskopie', $exception->getMessage());
        } finally {
            Str::createRandomStringsNormally();
        }

        $this->assertFalse($aufgerufen, 'Der Callback darf bei einer abgeschnittenen Kopie nicht laufen.');
        $this->assertSame([$key], Storage::disk(TemporaryUploadStorage::DISK)->allFiles($prefix), 'Die Arbeitskopie wird auch im Fehlerfall entfernt.');
    }

    public function test_loeschung_vergisst_den_dateischluessel(): void
    {
        $prefix = $this->storage->newPrefix();
        $keyring = new TemporaryUploadKeyring(new SodiumSecretstreamCipher);

        $this->storage->put($this->storage->originalKey($prefix), 'x');
        $this->assertSame(32, strlen($keyring->fileKeyForReading($prefix)));

        $this->assertTrue($this->storage->deletePrefix($prefix));

        $this->expectException(CipherIntegrityException::class);

        $keyring->fileKeyForReading($prefix);
    }

    public function test_liefert_praefixe_und_juengsten_aenderungszeitpunkt(): void
    {
        $a = $this->storage->newPrefix();
        $b = $this->storage->newPrefix();

        $this->storage->put($this->storage->originalKey($a), 'a');

        $this->assertContains($a, $this->storage->allPrefixes());
        $this->assertNotContains($b, $this->storage->allPrefixes());
        $this->assertEqualsWithDelta(time(), $this->storage->lastModifiedAt($a), 5);
        $this->assertNull($this->storage->lastModifiedAt($b));
    }

    public function test_umhuellter_schluessel_ist_druckbar_und_ohne_klartextschluessel(): void
    {
        $prefix = $this->storage->newPrefix();
        $keyring = new TemporaryUploadKeyring(new SodiumSecretstreamCipher);

        $umhuellt = $this->storage->wrappedKeyFor($prefix);

        $this->assertMatchesRegularExpression('#^s1\.[A-Za-z0-9+/=]+$#', $umhuellt);
        $this->assertLessThanOrEqual(255, strlen($umhuellt));
        $this->assertStringNotContainsString(base64_encode($keyring->fileKeyForReading($prefix)), $umhuellt);
    }
}
