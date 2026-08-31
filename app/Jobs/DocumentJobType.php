<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Queue\ProcessingJobHandler;

/**
 * Teiljobs des Dokumentlebenszyklus.
 *
 * Jeder Schritt ist ein eigener, kurzer und idempotenter Teiljob. Damit
 * genuegt ein Cron-Lauf mit begrenzter Laufzeit, und ein Abbruch mitten in der
 * Kette fuehrt nicht dazu, dass von vorn begonnen werden muss (ADR-006).
 *
 * DATENSCHUTZ: Der Payload eines Teiljobs enthaelt ausschliesslich die
 * Dokument-ID und technische Parameter wie die angekuendigte Dateiendung.
 * Niemals Dateiinhalte, Dateinamen, OCR-Text oder Prompts.
 */
enum DocumentJobType: string
{
    case ZUSAMMENSETZEN = 'dokument.zusammensetzen';
    case KLASSIFIZIEREN = 'dokument.klassifizieren';
    case EXTRAHIEREN = 'dokument.extrahieren';
    case QUELLEN_LOESCHEN = 'dokument.quellen-loeschen';

    public function label(): string
    {
        return match ($this) {
            self::ZUSAMMENSETZEN => 'Datei zusammensetzen und prüfen',
            self::KLASSIFIZIEREN => 'Dokumentart bestimmen',
            self::EXTRAHIEREN => 'Werte auslesen',
            self::QUELLEN_LOESCHEN => 'Quelldaten löschen',
        };
    }

    /**
     * @return class-string<ProcessingJobHandler>
     */
    public function handlerClass(): string
    {
        return match ($this) {
            self::ZUSAMMENSETZEN => AssembleUploadJob::class,
            self::KLASSIFIZIEREN => ClassifyDocumentJob::class,
            self::EXTRAHIEREN => ExtractDocumentJob::class,
            self::QUELLEN_LOESCHEN => DeleteDocumentSourcesJob::class,
        };
    }

    /**
     * Hoehere Prioritaet bedeutet frueheren Zugriff. Die Loeschung von
     * Quelldaten hat Vorrang vor jeder Auswertung, weil sie datenschutzkritisch
     * ist und nicht warten darf.
     */
    public function priority(): int
    {
        return match ($this) {
            self::QUELLEN_LOESCHEN => 10,
            self::ZUSAMMENSETZEN => 50,
            self::KLASSIFIZIEREN => 60,
            self::EXTRAHIEREN => 70,
        };
    }

    public function maxAttempts(): int
    {
        return match ($this) {
            // Die Loeschung wird oefter versucht als eine Auswertung. Ein
            // Aufgeben waere hier ein Datenschutzverstoss.
            self::QUELLEN_LOESCHEN => 5,
            default => 3,
        };
    }
}
