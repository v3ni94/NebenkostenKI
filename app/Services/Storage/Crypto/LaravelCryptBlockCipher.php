<?php

declare(strict_types=1);

namespace App\Services\Storage\Crypto;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;

/**
 * Rueckfallebene, falls die Erweiterung sodium wider Erwarten fehlt.
 *
 * Verwendet Laravels Encrypter mit AES-256-GCM, also ebenfalls ein
 * authentifiziertes Verfahren, und verarbeitet den Klartext blockweise:
 *
 *   CiphertextHeader (16 Byte)
 *   Bloecke: 4 Byte Laenge (Big Endian) + Encrypter-Chiffrat des Blocks
 *
 * Jeder Klartextblock traegt vor dem Inhalt seine laufende Nummer (8 Byte)
 * und ein Endkennzeichen (1 Byte). Beide werden beim Lesen geprueft, damit
 * Umordnen, Entfernen oder Abschneiden von Bloecken erkannt wird. Der
 * Encrypter selbst sichert jeden Block mit dem GCM-Tag.
 *
 * Der Dateischluessel wird mit demselben Encrypter unter dem Hauptschluessel
 * umhuellt.
 *
 * Diese Klasse ist bewusst nicht die Voreinstellung. Sie ist vorhanden, damit
 * eine Installation ohne sodium nicht in Klartext zurueckfaellt.
 */
final class LaravelCryptBlockCipher implements TemporaryUploadCipher
{
    public const ID = 2;

    public const BLOCK_BYTES = 1024 * 1024;

    private const CIPHER = 'aes-256-gcm';

    private const WRAP_PREFIX = 'c1.';

    public function id(): int
    {
        return self::ID;
    }

    public function newFileKey(): string
    {
        return Encrypter::generateKey(self::CIPHER);
    }

    public function wrapKey(string $fileKey, string $masterKey): string
    {
        return self::WRAP_PREFIX.$this->encrypter($masterKey)->encryptString($fileKey);
    }

    public function unwrapKey(string $wrapped, string $masterKey): string
    {
        if (! str_starts_with($wrapped, self::WRAP_PREFIX)) {
            throw CipherIntegrityException::keyUnwrapFailed();
        }

        try {
            return $this->encrypter($masterKey)->decryptString(substr($wrapped, strlen(self::WRAP_PREFIX)));
        } catch (DecryptException) {
            throw CipherIntegrityException::keyUnwrapFailed();
        }
    }

    public function openWriter($target, string $fileKey): EncryptingWriter
    {
        return new LaravelCryptEncryptingWriter($target, $this->encrypter($fileKey));
    }

    public function openReader($ciphertext, string $fileKey): PlaintextReader
    {
        return new LaravelCryptPlaintextReader($ciphertext, $this->encrypter($fileKey));
    }

    private function encrypter(string $key): Encrypter
    {
        return new Encrypter($key, self::CIPHER);
    }
}
