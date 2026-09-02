<?php

declare(strict_types=1);

namespace App\Services\Storage\Crypto;

/**
 * Authentifizierte Stromverschluesselung fuer den Kurzzeitbereich.
 *
 * ENTSCHEIDUNG: Es werden ausschliesslich fertige, authentifizierte Verfahren
 * verwendet. Primaer ist libsodium (XChaCha20-Poly1305 secretstream), als
 * Rueckfallebene Laravels Encrypter mit AES-256-GCM. Es gibt keinen Schalter,
 * der die Verschluesselung abschaltet, auch nicht fuer Tests.
 *
 * Schluesselmodell: Jeder Upload erhaelt einen zufaelligen Dateischluessel.
 * Dieser wird mit einem aus APP_KEY abgeleiteten Hauptschluessel umhuellt
 * (siehe TemporaryUploadKeyring) und in temporary_uploads gespeichert. Der
 * Klartextschluessel liegt niemals auf der Platte.
 */
interface TemporaryUploadCipher
{
    /**
     * Verfahrenskennung im Vorspann jedes Chiffrats und als Praefix des
     * umhuellten Dateischluessels.
     */
    public function id(): int;

    /**
     * Zufaelliger Dateischluessel in Rohbytes.
     */
    public function newFileKey(): string;

    /**
     * Umhuellt einen Dateischluessel mit dem Hauptschluessel. Das Ergebnis ist
     * druckbar und fuer eine Datenbankspalte geeignet.
     */
    public function wrapKey(string $fileKey, string $masterKey): string;

    /**
     * @throws CipherIntegrityException wenn der Hauptschluessel nicht passt
     */
    public function unwrapKey(string $wrapped, string $masterKey): string;

    /**
     * @param  resource  $target  beschreib- und positionierbarer Zielstrom
     */
    public function openWriter($target, string $fileKey): EncryptingWriter;

    /**
     * @param  resource  $ciphertext  lesbarer Chiffratstrom, positioniert am Anfang
     *
     * @throws CipherIntegrityException
     */
    public function openReader($ciphertext, string $fileKey): PlaintextReader;
}
