<?php

declare(strict_types=1);

namespace App\Console\Commands\Privacy;

use App\Application\Privacy\EnforceRetention;
use Illuminate\Console\Command;

/**
 * Setzt die Aufbewahrungsfristen für strukturierte Extraktionsdaten und
 * erzeugte PDFs durch (Masterprompt 19).
 *
 * Sind die Fristen nicht gesetzt, löscht der Lauf nichts und weist darauf hin,
 * dass die Festlegung vor Livegang zu treffen ist. Der Rückgabewert bleibt
 * dabei erfolgreich, weil ein fehlender kaufmännischer Beschluss kein
 * technischer Fehler ist; der Adminbereich führt den Punkt als offene Aufgabe.
 */
final class EnforceRetentionCommand extends Command
{
    protected $signature = 'smartabrechnen:enforce-retention';

    protected $description = 'Löscht abgelaufene strukturierte Extraktionsdaten und abgelaufene erzeugte PDFs.';

    public function handle(EnforceRetention $retention): int
    {
        $bericht = $retention();

        if (! $bericht->extractedDataConfigured()) {
            $this->warn(
                'Die Aufbewahrungsfrist für strukturierte Extraktionsdaten ist nicht gesetzt. Es wurde '
                .'nichts gelöscht. Die Frist ist vor Livegang festzulegen '
                .'(EXTRACTED_DATA_RETENTION_DAYS).'
            );
        }

        if (! $bericht->generatedPdfConfigured()) {
            $this->warn(
                'Die Aufbewahrungsfrist für erzeugte PDFs ist nicht gesetzt. Es wurde nichts gelöscht. '
                .'Die Frist ist vor Livegang festzulegen (GENERATED_PDF_RETENTION_DAYS).'
            );
        }

        $this->line($bericht->summary());

        if ($bericht->failedArtifacts > 0) {
            $this->warn(sprintf(
                'Achtung: %d Artefaktdateien konnten nicht gelöscht werden. Der Adminbereich führt das '
                .'als offenen Datenschutzpunkt.',
                $bericht->failedArtifacts,
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
