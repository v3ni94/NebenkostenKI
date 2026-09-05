<?php

declare(strict_types=1);

namespace App\Services\Storage\Crypto;

/**
 * Unverschluesselter Vorspann jeder Datei im Kurzzeitbereich.
 *
 * Aufbau, 16 Byte:
 *
 *   Byte 0 bis 3    Kennung "SAQ1" (Smart Abrechnen Quarantaene, Format 1)
 *   Byte 4          Verfahrenskennung, siehe TemporaryUploadCipher::id()
 *   Byte 5 bis 7    reserviert, immer 0
 *   Byte 8 bis 15   Klartextlaenge in Byte, Big Endian ohne Vorzeichen
 *
 * Die Klartextlaenge wird beim Abschluss des Schreibvorgangs nachgetragen,
 * weil sie beim Oeffnen eines Stroms noch nicht bekannt ist. Sie ist nicht
 * durch den Authentifizierungstag geschuetzt, wird aber beim Lesen gegen die
 * tatsaechlich entschluesselte Menge geprueft. Eine Manipulation faellt damit
 * auf, sobald die Datei vollstaendig gelesen wird. Kennung und Verfahren sind
 * als Zusatzdaten (AAD) an jeden Block gebunden.
 */
final class CiphertextHeader
{
    public const MAGIC = 'SAQ1';

    public const LENGTH = 16;

    public function __construct(
        public readonly int $cipherId,
        public readonly int $plaintextLength,
    ) {}

    public function encode(): string
    {
        return self::MAGIC.pack('C', $this->cipherId)."\0\0\0".pack('J', $this->plaintextLength);
    }

    /**
     * Zusatzdaten, die an jeden Block gebunden werden. Bewusst ohne die
     * Klartextlaenge, weil diese beim Schreiben noch nicht feststeht.
     */
    public function additionalData(): string
    {
        return self::MAGIC.pack('C', $this->cipherId);
    }

    /**
     * @throws CipherIntegrityException wenn der Vorspann fehlt oder fremd ist
     */
    public static function decode(string $bytes): self
    {
        if (strlen($bytes) !== self::LENGTH || ! str_starts_with($bytes, self::MAGIC)) {
            throw CipherIntegrityException::invalidHeader();
        }

        $cipherId = unpack('C', $bytes, 4);
        $length = unpack('J', $bytes, 8);

        if ($cipherId === false || $length === false) {
            throw CipherIntegrityException::invalidHeader();
        }

        return new self((int) $cipherId[1], (int) $length[1]);
    }

    /**
     * Liest den Vorspann vom Anfang eines geoeffneten Chiffratstroms.
     *
     * @param  resource  $stream
     *
     * @throws CipherIntegrityException
     */
    public static function read($stream): self
    {
        $bytes = fread($stream, self::LENGTH);

        if ($bytes === false) {
            throw CipherIntegrityException::invalidHeader();
        }

        return self::decode($bytes);
    }
}
