<?php

declare(strict_types=1);

namespace App\Services\Storage\Crypto;

use RuntimeException;

/**
 * Primaeres Verfahren: XChaCha20-Poly1305 secretstream aus libsodium.
 *
 * Dateiaufbau:
 *
 *   CiphertextHeader (16 Byte)
 *   secretstream-Kopf (24 Byte)
 *   Bloecke: jeder Klartextblock von hoechstens BLOCK_BYTES wird zu einem
 *            Chiffratblock von Blocklaenge + 17 Byte. Der letzte Block traegt
 *            den Tag FINAL. Eine leere Datei besteht aus genau einem leeren
 *            FINAL-Block.
 *
 * Der Nonce wird von libsodium im secretstream-Kopf gefuehrt und je Block
 * fortgeschrieben. Umordnen, Entfernen, Abschneiden oder Anhaengen von
 * Bloecken wird dadurch erkannt.
 *
 * Der Dateischluessel wird mit crypto_secretbox unter dem Hauptschluessel
 * umhuellt; der Nonce steht vor dem Chiffrat.
 */
final class SodiumSecretstreamCipher implements TemporaryUploadCipher
{
    public const ID = 1;

    public const BLOCK_BYTES = 1024 * 1024;

    private const WRAP_PREFIX = 's1.';

    public function __construct()
    {
        if (! self::isSupported()) {
            throw new RuntimeException('Die PHP-Erweiterung sodium ist nicht verfuegbar.');
        }
    }

    public static function isSupported(): bool
    {
        return function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')
            && function_exists('sodium_crypto_secretbox');
    }

    public function id(): int
    {
        return self::ID;
    }

    public function newFileKey(): string
    {
        return sodium_crypto_secretstream_xchacha20poly1305_keygen();
    }

    public function wrapKey(string $fileKey, string $masterKey): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox($fileKey, $nonce, $masterKey);

        return self::WRAP_PREFIX.base64_encode($nonce.$box);
    }

    public function unwrapKey(string $wrapped, string $masterKey): string
    {
        if (! str_starts_with($wrapped, self::WRAP_PREFIX)) {
            throw CipherIntegrityException::keyUnwrapFailed();
        }

        $raw = base64_decode(substr($wrapped, strlen(self::WRAP_PREFIX)), true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw CipherIntegrityException::keyUnwrapFailed();
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $fileKey = sodium_crypto_secretbox_open($box, $nonce, $masterKey);

        if ($fileKey === false) {
            throw CipherIntegrityException::keyUnwrapFailed();
        }

        return $fileKey;
    }

    public function openWriter($target, string $fileKey): EncryptingWriter
    {
        return new SodiumEncryptingWriter($target, $fileKey);
    }

    public function openReader($ciphertext, string $fileKey): PlaintextReader
    {
        return new SodiumPlaintextReader($ciphertext, $fileKey);
    }
}
