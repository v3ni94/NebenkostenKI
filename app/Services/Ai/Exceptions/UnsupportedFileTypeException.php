<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

/**
 * Der Provider unterstuetzt den MIME-Typ der Quelldatei nicht.
 *
 * Die Pruefung erfolgt lokal vor dem Versand, damit keine unnoetigen
 * Dokumentinhalte an einen Provider uebertragen werden. Berechtigt zum
 * Fallback auf einen Provider mit anderer Dateiunterstuetzung.
 */
final class UnsupportedFileTypeException extends AiException
{
    public static function forMimeType(string $providerKey, string $mimeType): self
    {
        return new self(sprintf(
            'Provider "%s" unterstuetzt den Dateityp "%s" nicht.',
            $providerKey,
            $mimeType,
        ));
    }
}
