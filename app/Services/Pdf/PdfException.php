<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use RuntimeException;

/**
 * Fehler der PDF-Erzeugung. Ein fehlerhaftes PDF wird niemals gespeichert
 * oder ausgeliefert.
 */
final class PdfException extends RuntimeException
{
    public static function emptyOutput(string $template): self
    {
        return new self(sprintf('Die Vorlage "%s" hat kein PDF erzeugt.', $template));
    }

    public static function invalidOutput(string $template): self
    {
        return new self(sprintf('Die Vorlage "%s" hat keine gültige PDF-Datei erzeugt.', $template));
    }

    public static function engineFailure(string $template, string $reason): self
    {
        return new self(sprintf('Die Vorlage "%s" konnte nicht gerendert werden: %s', $template, $reason));
    }
}
