<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Services\Storage\Crypto\CipherIntegrityException;
use App\Services\Storage\Crypto\CiphertextHeader;
use App\Services\Storage\Crypto\LaravelCryptBlockCipher;
use App\Services\Storage\Crypto\PlaintextStreamWrapper;
use App\Services\Storage\Crypto\SodiumSecretstreamCipher;
use App\Services\Storage\Crypto\TemporaryUploadCipher;
use App\Services\Storage\Crypto\TemporaryUploadCipherFactory;
use RuntimeException;
use Tests\TestCase;

/**
 * Prueft die authentifizierte Stromverschluesselung des Kurzzeitbereichs.
 *
 * VERBINDLICH: Roundtrip byteidentisch, Manipulation wird erkannt und
 * abgelehnt, ein fremder Schluessel liest nichts, Klartext erscheint nicht im
 * Chiffrat. Geprueft werden das primaere Verfahren (libsodium) und die
 * Rueckfallebene (Laravel Encrypter, AES-256-GCM).
 */
class TemporaryUploadCipherTest extends TestCase
{
    private const MARKER = 'VERTRAULICHER-BELEG-0815';

    public function test_die_werkseinstellung_ist_libsodium(): void
    {
        $this->assertTrue(SodiumSecretstreamCipher::isSupported(), 'ext-sodium muss vorhanden sein.');
        $this->assertInstanceOf(SodiumSecretstreamCipher::class, TemporaryUploadCipherFactory::make());
    }

    public function test_roundtrip_kleiner_inhalt_ist_byteidentisch(): void
    {
        $cipher = new SodiumSecretstreamCipher;
        $key = $cipher->newFileKey();
        $klartext = SampleFiles::pdf(2).self::MARKER;

        $chiffrat = $this->verschluessele($cipher, $key, $klartext);

        $this->assertSame($klartext, $this->entschluessele($cipher, $key, $chiffrat));
    }

    public function test_roundtrip_leerer_inhalt(): void
    {
        $cipher = new SodiumSecretstreamCipher;
        $key = $cipher->newFileKey();

        $chiffrat = $this->verschluessele($cipher, $key, '');

        $this->assertSame('', $this->entschluessele($cipher, $key, $chiffrat));
        $this->assertSame(0, CiphertextHeader::decode(substr($chiffrat, 0, 16))->plaintextLength);
    }

    public function test_roundtrip_oberhalb_der_blockgroesse_ist_byteidentisch(): void
    {
        $cipher = new SodiumSecretstreamCipher;
        $key = $cipher->newFileKey();
        $klartext = random_bytes(SodiumSecretstreamCipher::BLOCK_BYTES * 2 + 12345);

        $chiffrat = $this->verschluessele($cipher, $key, $klartext);

        $this->assertSame(strlen($klartext), CiphertextHeader::decode(substr($chiffrat, 0, 16))->plaintextLength);
        $this->assertSame(hash('sha256', $klartext), hash('sha256', $this->entschluessele($cipher, $key, $chiffrat)));
    }

    public function test_roundtrip_genau_eine_blockgroesse(): void
    {
        $cipher = new SodiumSecretstreamCipher;
        $key = $cipher->newFileKey();
        $klartext = random_bytes(SodiumSecretstreamCipher::BLOCK_BYTES);

        $chiffrat = $this->verschluessele($cipher, $key, $klartext);

        $this->assertSame($klartext, $this->entschluessele($cipher, $key, $chiffrat));
    }

    public function test_chiffrat_enthaelt_den_klartext_nicht(): void
    {
        $cipher = new SodiumSecretstreamCipher;
        $klartext = str_repeat(self::MARKER, 100);

        $chiffrat = $this->verschluessele($cipher, $cipher->newFileKey(), $klartext);

        $this->assertStringNotContainsString(self::MARKER, $chiffrat);
        $this->assertStringStartsWith(CiphertextHeader::MAGIC, $chiffrat);
    }

    public function test_gleicher_klartext_ergibt_unterschiedliche_chiffrate(): void
    {
        $cipher = new SodiumSecretstreamCipher;
        $key = $cipher->newFileKey();

        $this->assertNotSame(
            $this->verschluessele($cipher, $key, 'derselbe Inhalt'),
            $this->verschluessele($cipher, $key, 'derselbe Inhalt'),
        );
    }

    public function test_gekipptes_byte_wird_beim_lesen_erkannt_und_abgelehnt(): void
    {
        $cipher = new SodiumSecretstreamCipher;
        $key = $cipher->newFileKey();

        $chiffrat = $this->verschluessele($cipher, $key, SampleFiles::pdf(3));

        $position = CiphertextHeader::LENGTH + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES + 7;
        $chiffrat[$position] = chr(ord($chiffrat[$position]) ^ 0x01);

        $this->expectException(CipherIntegrityException::class);

        $this->entschluessele($cipher, $key, $chiffrat);
    }

    public function test_abgeschnittenes_chiffrat_wird_abgelehnt(): void
    {
        $cipher = new SodiumSecretstreamCipher;
        $key = $cipher->newFileKey();

        $chiffrat = substr($this->verschluessele($cipher, $key, SampleFiles::pdf(3)), 0, -5);

        $this->expectException(CipherIntegrityException::class);

        $this->entschluessele($cipher, $key, $chiffrat);
    }

    public function test_angehaengte_daten_werden_abgelehnt(): void
    {
        $cipher = new SodiumSecretstreamCipher;
        $key = $cipher->newFileKey();

        $chiffrat = $this->verschluessele($cipher, $key, SampleFiles::pdf(3)).'x';

        $this->expectException(CipherIntegrityException::class);

        $this->entschluessele($cipher, $key, $chiffrat);
    }

    public function test_manipulierte_laenge_im_vorspann_wird_erkannt(): void
    {
        $cipher = new SodiumSecretstreamCipher;
        $key = $cipher->newFileKey();

        $chiffrat = $this->verschluessele($cipher, $key, SampleFiles::pdf(3));
        $chiffrat = substr_replace($chiffrat, pack('J', 4), 8, 8);

        $this->expectException(CipherIntegrityException::class);

        $this->entschluessele($cipher, $key, $chiffrat);
    }

    public function test_fremder_schluessel_liest_nichts(): void
    {
        $cipher = new SodiumSecretstreamCipher;

        $chiffrat = $this->verschluessele($cipher, $cipher->newFileKey(), SampleFiles::pdf(3));

        $this->expectException(CipherIntegrityException::class);

        $this->entschluessele($cipher, $cipher->newFileKey(), $chiffrat);
    }

    public function test_klartext_ohne_vorspann_wird_abgelehnt(): void
    {
        $cipher = new SodiumSecretstreamCipher;

        $this->expectException(CipherIntegrityException::class);

        $this->entschluessele($cipher, $cipher->newFileKey(), SampleFiles::pdf(2));
    }

    public function test_schluessel_umhuellen_und_oeffnen(): void
    {
        $cipher = new SodiumSecretstreamCipher;
        $master = random_bytes(32);
        $fileKey = $cipher->newFileKey();

        $umhuellt = $cipher->wrapKey($fileKey, $master);

        $this->assertStringStartsWith('s1.', $umhuellt);
        $this->assertStringNotContainsString(base64_encode($fileKey), $umhuellt);
        $this->assertSame($fileKey, $cipher->unwrapKey($umhuellt, $master));

        $this->expectException(CipherIntegrityException::class);

        $cipher->unwrapKey($umhuellt, random_bytes(32));
    }

    public function test_rueckfallebene_roundtrip_ueber_mehrere_bloecke(): void
    {
        $cipher = new LaravelCryptBlockCipher;
        $key = $cipher->newFileKey();
        $klartext = random_bytes(LaravelCryptBlockCipher::BLOCK_BYTES + 777).self::MARKER;

        $chiffrat = $this->verschluessele($cipher, $key, $klartext);

        $this->assertStringNotContainsString(self::MARKER, $chiffrat);
        $this->assertSame(LaravelCryptBlockCipher::ID, CiphertextHeader::decode(substr($chiffrat, 0, 16))->cipherId);
        $this->assertSame($klartext, $this->entschluessele($cipher, $key, $chiffrat));
        $this->assertSame('', $this->entschluessele($cipher, $key, $this->verschluessele($cipher, $key, '')));
    }

    public function test_rueckfallebene_erkennt_manipulation_und_fremden_schluessel(): void
    {
        $cipher = new LaravelCryptBlockCipher;
        $key = $cipher->newFileKey();

        $chiffrat = $this->verschluessele($cipher, $key, SampleFiles::pdf(2));

        $position = CiphertextHeader::LENGTH + 4 + 40;
        $manipuliert = $chiffrat;
        $manipuliert[$position] = chr(ord($manipuliert[$position]) ^ 0x01);

        try {
            $this->entschluessele($cipher, $key, $manipuliert);
            $this->fail('Ein gekipptes Byte muss erkannt werden.');
        } catch (CipherIntegrityException) {
            $this->addToAssertionCount(1);
        }

        try {
            $this->entschluessele($cipher, $cipher->newFileKey(), $chiffrat);
            $this->fail('Ein fremder Schluessel darf nichts lesen.');
        } catch (CipherIntegrityException) {
            $this->addToAssertionCount(1);
        }

        $umhuellt = $cipher->wrapKey($key, random_bytes(32));
        $this->assertStringStartsWith('c1.', $umhuellt);
        $this->assertSame($cipher, TemporaryUploadCipherFactory::forWrappedKey($umhuellt) instanceof LaravelCryptBlockCipher ? $cipher : null);
    }

    public function test_verfahren_werden_ueber_die_kennung_aufgeloest(): void
    {
        $this->assertInstanceOf(SodiumSecretstreamCipher::class, TemporaryUploadCipherFactory::byId(1));
        $this->assertInstanceOf(LaravelCryptBlockCipher::class, TemporaryUploadCipherFactory::byId(2));

        $this->expectException(CipherIntegrityException::class);

        TemporaryUploadCipherFactory::byId(99);
    }

    public function test_klartextstrom_ist_nicht_positionierbar_und_meldet_groesse(): void
    {
        $cipher = new SodiumSecretstreamCipher;
        $key = $cipher->newFileKey();
        $klartext = SampleFiles::pdf(2);

        $chiffrat = $this->verschluessele($cipher, $key, $klartext);

        $stream = $this->oeffneKlartextstrom($cipher, $key, $chiffrat);

        $stat = fstat($stream);

        $this->assertIsArray($stat);
        $this->assertSame(strlen($klartext), $stat['size']);
        $this->assertSame(-1, @fseek($stream, 0));
        $this->assertSame(substr($klartext, 0, 5), fread($stream, 5));
        $this->assertSame(5, ftell($stream));

        fclose($stream);
    }

    private function verschluessele(TemporaryUploadCipher $cipher, string $key, string $klartext): string
    {
        $pfad = SampleFiles::temporaryPath('bin');
        $ziel = fopen($pfad, 'w+b');

        if ($ziel === false) {
            throw new RuntimeException('Zieldatei nicht anlegbar.');
        }

        $writer = $cipher->openWriter($ziel, $key);

        // In unregelmaessigen Stuecken schreiben, damit die Pufferung greift.
        foreach (str_split($klartext === '' ? ' ' : $klartext, 700_000) as $stueck) {
            if ($klartext !== '') {
                $writer->write($stueck);
            }
        }

        $geschrieben = $writer->finish();

        $this->assertSame(strlen($klartext), $geschrieben);

        $chiffrat = (string) file_get_contents($pfad);
        unlink($pfad);

        return $chiffrat;
    }

    private function entschluessele(TemporaryUploadCipher $cipher, string $key, string $chiffrat): string
    {
        $stream = $this->oeffneKlartextstrom($cipher, $key, $chiffrat);

        try {
            $klartext = '';

            while (! feof($stream)) {
                $block = fread($stream, 65536);

                if ($block === false) {
                    throw new RuntimeException('Lesefehler.');
                }

                $klartext .= $block;
            }

            return $klartext;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return resource
     */
    private function oeffneKlartextstrom(TemporaryUploadCipher $cipher, string $key, string $chiffrat)
    {
        $quelle = fopen('php://memory', 'w+b');

        if ($quelle === false) {
            throw new RuntimeException('Speicherstrom nicht anlegbar.');
        }

        fwrite($quelle, $chiffrat);
        rewind($quelle);

        return PlaintextStreamWrapper::open($cipher->openReader($quelle, $key));
    }
}
