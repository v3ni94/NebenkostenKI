<?php

declare(strict_types=1);

namespace App\Application\Documents\Contracts;

use App\Application\Documents\Dto\ClassificationOutcome;
use App\Models\Document;
use App\Models\TemporaryUpload;

/**
 * Klassifikation eines Dokuments nach Abschnitt 6.2.
 *
 * Schmale Schnittstelle zwischen Lebenszyklus und KI-Schicht. Die Umsetzung
 * liest die Datei aus dem Kurzzeitbereich, ruft den Provider auf und gibt
 * ausschliesslich Dokumenttyp und Konfidenz zurueck. Sie speichert keine
 * Rohantwort und keinen Volltext.
 */
interface DocumentClassifier
{
    public function classify(Document $document, TemporaryUpload $upload): ClassificationOutcome;
}
