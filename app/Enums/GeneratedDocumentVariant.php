<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Variante eines erzeugten PDFs.
 *
 * Vorschau und Finalversion werden getrennt gespeichert. Die Vorschau traegt
 * ein serverseitig eingebranntes Wasserzeichen auf jeder Seite.
 */
enum GeneratedDocumentVariant: string
{
    case VORSCHAU = 'VORSCHAU';
    case FINAL = 'FINAL';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::VORSCHAU => 'Vorschau mit Wasserzeichen',
            self::FINAL => 'Finalversion',
        };
    }
}
