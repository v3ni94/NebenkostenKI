<?php

declare(strict_types=1);

namespace App\Application\Documents\Contracts;

use App\Application\Documents\Dto\ExtractionOutcome;
use App\Models\Document;
use App\Models\TemporaryUpload;

/**
 * Strukturierte Extraktion gegen ein striktes JSON-Schema (Abschnitt 6.3
 * Schritte 8 bis 10).
 *
 * Die Umsetzung persistiert ausschliesslich strukturierte Felder, Seitenzahl
 * und minimale Fundstellenausschnitte. Vollstaendiger OCR-Text, Seitenbilder
 * und Rohantworten bleiben im Arbeitsspeicher oder im Kurzzeitbereich und
 * werden mit dem Original geloescht.
 *
 * Die Rueckgabe entscheidet ueber den Loeschzeitpunkt: Bei Erfolg und bei
 * endgueltigem Fehler wird sofort geloescht.
 */
interface DocumentExtractor
{
    public function extract(Document $document, TemporaryUpload $upload): ExtractionOutcome;
}
