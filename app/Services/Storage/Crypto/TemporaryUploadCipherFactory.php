<?php

declare(strict_types=1);

namespace App\Services\Storage\Crypto;

/**
 * Waehlt das Verfahren fuer den Kurzzeitbereich.
 *
 * Es gibt keine Konfiguration und keinen Schalter. Ist sodium vorhanden,
 * wird es verwendet; andernfalls die Rueckfallebene mit Laravels Encrypter.
 * Ein Zustand ohne Verschluesselung existiert nicht.
 */
final class TemporaryUploadCipherFactory
{
    public static function make(): TemporaryUploadCipher
    {
        if (SodiumSecretstreamCipher::isSupported()) {
            return new SodiumSecretstreamCipher;
        }

        return new LaravelCryptBlockCipher;
    }

    /**
     * Verfahren zu einer Kennung aus Vorspann oder umhuelltem Schluessel.
     *
     * @throws CipherIntegrityException wenn die Kennung unbekannt ist oder
     *                                  das Verfahren hier nicht verfuegbar ist
     */
    public static function byId(int $cipherId): TemporaryUploadCipher
    {
        return match ($cipherId) {
            SodiumSecretstreamCipher::ID => SodiumSecretstreamCipher::isSupported()
                ? new SodiumSecretstreamCipher
                : throw CipherIntegrityException::unsupportedCipher($cipherId),
            LaravelCryptBlockCipher::ID => new LaravelCryptBlockCipher,
            default => throw CipherIntegrityException::unsupportedCipher($cipherId),
        };
    }

    /**
     * Verfahren, mit dem ein umhuellter Schluessel erzeugt wurde. Das Praefix
     * vor dem Punkt benennt es.
     */
    public static function forWrappedKey(string $wrapped): TemporaryUploadCipher
    {
        return match (true) {
            str_starts_with($wrapped, 's1.') => self::byId(SodiumSecretstreamCipher::ID),
            str_starts_with($wrapped, 'c1.') => self::byId(LaravelCryptBlockCipher::ID),
            default => throw CipherIntegrityException::keyUnwrapFailed(),
        };
    }
}
