<?php

declare(strict_types=1);

namespace App\Application\Privacy;

use App\Application\Privacy\Dto\RetentionReport;
use App\Enums\GeneratedDocumentKind;
use App\Models\DocumentPage;
use App\Models\ExtractedField;
use App\Models\GeneratedDocument;
use App\Services\Storage\ArtifactStorage;
use Illuminate\Support\Carbon;

/**
 * Durchsetzung der Aufbewahrungsfristen (Masterprompt 19).
 *
 * ZWEI FRISTEN, beide aus der Konfiguration:
 *
 *   config('smartabrechnen.retention.extracted_data_days')
 *       Höchstalter der dauerhaft gespeicherten strukturierten
 *       Extraktionsdaten.
 *   config('smartabrechnen.retention.generated_pdf_days')
 *       Höchstalter der erzeugten PDFs.
 *
 * IST EINE FRIST NICHT GESETZT, WIRD NICHTS GELÖSCHT. Das ist die verbindliche
 * Vorgabe: Eine fehlende kaufmännische Festlegung darf nicht dazu führen, dass
 * die Anwendung eine Frist erfindet und Kundendaten löscht. Der Lauf meldet den
 * offenen Punkt, damit er vor Livegang festgelegt wird.
 *
 * NICHT GELÖSCHT werden Rechnungen der Hausverwaltung Müller GmbH. Sie
 * unterliegen handels- und steuerrechtlichen Aufbewahrungspflichten und werden
 * von dieser Frist ausdrücklich ausgenommen.
 *
 * IDEMPOTENZ: Der Lauf wählt ausschließlich über das Alter aus. Ein zweiter
 * Lauf findet dieselben Datensätze nicht mehr, weil sie nicht mehr existieren.
 */
final class EnforceRetention
{
    public function __construct(private readonly ArtifactStorage $artifacts) {}

    public function __invoke(?Carbon $now = null): RetentionReport
    {
        $jetzt = $now ?? Carbon::now();

        $extractedDataDays = $this->days('extracted_data_days');
        $generatedPdfDays = $this->days('generated_pdf_days');

        $felder = 0;
        $seiten = 0;
        $dokumente = 0;
        $dateien = 0;
        $fehler = 0;

        if ($extractedDataDays !== null && $extractedDataDays > 0) {
            $grenze = $jetzt->copy()->subDays($extractedDataDays);

            $felder = ExtractedField::query()->where('created_at', '<', $grenze)->delete();
            $seiten = DocumentPage::query()->where('created_at', '<', $grenze)->delete();
        }

        if ($generatedPdfDays !== null && $generatedPdfDays > 0) {
            $grenze = $jetzt->copy()->subDays($generatedPdfDays);

            /** @var list<GeneratedDocument> $abgelaufen */
            $abgelaufen = GeneratedDocument::query()
                ->where('generated_at', '<', $grenze)
                ->where('kind', '!=', GeneratedDocumentKind::HVM_RECHNUNG->value)
                ->get()
                ->all();

            foreach ($abgelaufen as $dokument) {
                $pfad = $dokument->getAttribute('storage_path');

                if (is_string($pfad) && $pfad !== '' && $this->artifacts->exists($pfad)) {
                    if ($this->artifacts->delete($pfad)) {
                        $dateien++;
                    } else {
                        $fehler++;
                    }
                }

                $dokument->delete();
                $dokumente++;
            }
        }

        return new RetentionReport(
            extractedDataDays: $extractedDataDays,
            generatedPdfDays: $generatedPdfDays,
            deletedExtractedFields: $felder,
            deletedDocumentPages: $seiten,
            deletedGeneratedDocuments: $dokumente,
            deletedArtifacts: $dateien,
            failedArtifacts: $fehler,
        );
    }

    /**
     * Frist in Tagen. Ein nicht gesetzter oder unbrauchbarer Wert ergibt null
     * und führt dazu, dass nichts gelöscht wird.
     */
    private function days(string $key): ?int
    {
        $wert = config('smartabrechnen.retention.'.$key);

        if (! is_numeric($wert)) {
            return null;
        }

        $tage = (int) $wert;

        return $tage > 0 ? $tage : null;
    }
}
