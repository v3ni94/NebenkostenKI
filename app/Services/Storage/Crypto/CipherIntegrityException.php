<?php

declare(strict_types=1);

namespace App\Services\Storage\Crypto;

use RuntimeException;

/**
 * Ein Chiffrat im Kurzzeitbereich ist nicht lesbar: fremder Vorspann,
 * fehlgeschlagene Authentifizierung, abgeschnittener oder verlaengerter
 * Strom oder ein nicht passender Schluessel.
 *
 * Die Ausnahme wird bewusst geworfen und nicht als leerer Inhalt kaschiert.
 * Ein manipuliertes Chiffrat darf niemals stumm als Muell an die Pruefkette
 * oder den KI-Provider weitergegeben werden.
 *
 * DATENSCHUTZ: Die Meldungen enthalten keinen Pfad, keinen Schluessel und
 * keinen Inhalt.
 */
final class CipherIntegrityException extends RuntimeException
{
    public static function invalidHeader(): self
    {
        return new self('Die Datei im Kurzzeitbereich traegt keinen gueltigen Vorspann.');
    }

    public static function authenticationFailed(): self
    {
        return new self('Die Authentifizierung eines Blocks im Kurzzeitbereich ist fehlgeschlagen.');
    }

    public static function truncated(): self
    {
        return new self('Das Chiffrat im Kurzzeitbereich ist unvollstaendig.');
    }

    public static function trailingData(): self
    {
        return new self('Das Chiffrat im Kurzzeitbereich enthaelt Daten nach dem Endblock.');
    }

    public static function lengthMismatch(): self
    {
        return new self('Die Klartextlaenge im Vorspann stimmt nicht mit dem Inhalt ueberein.');
    }

    public static function keyUnavailable(): self
    {
        return new self('Fuer diesen Upload ist kein Dateischluessel verfuegbar.');
    }

    public static function keyUnwrapFailed(): self
    {
        return new self('Der Dateischluessel konnte mit dem Anwendungsschluessel nicht entschluesselt werden.');
    }

    public static function unsupportedCipher(int $cipherId): self
    {
        return new self(sprintf('Das Verfahren %d wird von dieser Installation nicht unterstuetzt.', $cipherId));
    }
}
