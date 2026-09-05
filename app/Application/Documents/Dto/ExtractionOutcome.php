<?php

declare(strict_types=1);

namespace App\Application\Documents\Dto;

/**
 * Ergebnis eines Extraktionslaufs aus Sicht des Lebenszyklus.
 *
 * Die Anwendungsschicht interessiert nur, ob die Extraktion schemavalidiert
 * abgeschlossen ist. Erst dann werden die Quelldaten geloescht
 * (Abschnitt 6.3 Schritt 15). Die extrahierten Felder selbst werden von der
 * KI-Schicht persistiert und tauchen hier bewusst nicht auf.
 */
final class ExtractionOutcome
{
    private function __construct(
        public readonly bool $successful,
        public readonly bool $schemaValid,
        public readonly int $persistedFieldCount = 0,
        public readonly ?int $pageCount = null,
        public readonly ?string $errorCode = null,
        public readonly bool $permanent = true,
    ) {}

    public static function completed(int $persistedFieldCount, ?int $pageCount = null): self
    {
        return new self(true, true, $persistedFieldCount, $pageCount);
    }

    /**
     * Endgueltig fehlgeschlagen. Die Quelldaten werden sofort geloescht, ein
     * neuer Versuch erfordert einen erneuten Upload.
     */
    public static function failedPermanently(string $errorCode): self
    {
        return new self(false, false, 0, null, $errorCode, true);
    }

    /**
     * Voruebergehend fehlgeschlagen, zum Beispiel Ratenbegrenzung des
     * Providers. Der Teiljob wird mit Backoff wiederholt.
     */
    public static function failedTemporarily(string $errorCode): self
    {
        return new self(false, false, 0, null, $errorCode, false);
    }
}
