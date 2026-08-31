<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

use App\Services\Ai\Exceptions\ProviderFileDeletionFailedException;
use App\Services\Ai\Exceptions\SchemaValidationFailedException;

/**
 * Ergebnis eines schemagebundenen KI-Aufrufs.
 *
 * VERBINDLICHE DATENSCHUTZREGELN (Abschnitte 6.4 und 13.5):
 *
 * 1. Das Ergebnis enthaelt ausschliesslich die freigegebenen strukturierten
 *    Felder. Die rohe Modellantwort wird nach der Validierung im Speicher
 *    verworfen und ist hier bewusst nicht abrufbar.
 * 2. Die Loeschstatus der temporaer beim Provider angelegten Dateien sind
 *    Teil dieses DTOs, damit die Application-Schicht sie in
 *    source_deletion_events protokollieren kann.
 * 3. Ein fehlgeschlagener Loeschvorgang ist im Adminbereich als kritischer
 *    Datenschutzalarm anzuzeigen und erneut zu bearbeiten.
 *
 * Bei Status FEHLGESCHLAGEN liefert die Schicht bewusst ein Ergebnis und
 * keine unbehandelte Ausnahme, damit der Aufrufer die manuelle Erfassung
 * anbieten kann (Abschnitt 6.5 und Grundsatz 5).
 */
final class ExtractionResult
{
    /**
     * @param  array<string, mixed>  $data  Validierte strukturierte Extraktionsdaten.
     * @param  array<string, ExtractedValue>  $fields  Flache Feldliste mit Quellenbezug, Schluessel ist der Schemapfad.
     * @param  list<SchemaViolation>  $violations  Nur bei Status FEHLGESCHLAGEN gefuellt.
     * @param  list<ProviderFileDeletionOutcome>  $providerFileDeletions
     */
    public function __construct(
        public readonly AiResultStatus $status,
        public readonly array $data,
        public readonly array $fields,
        public readonly AiCallMetadata $metadata,
        public readonly array $violations = [],
        public readonly array $providerFileDeletions = [],
        public readonly ?ConflictReport $conflictReport = null,
    ) {}

    public function isValidated(): bool
    {
        return $this->status === AiResultStatus::VALIDIERT;
    }

    public function requiresManualEntry(): bool
    {
        return $this->status->requiresManualEntry();
    }

    public function hasConflict(): bool
    {
        return $this->status === AiResultStatus::KONFLIKT;
    }

    /**
     * Alle Felder, die wegen zu geringer Konfidenz ausdruecklich zu pruefen
     * sind. Die Oberflaeche hebt sie gelb hervor.
     *
     * @return list<string>
     */
    public function reviewRequiredPaths(): array
    {
        $paths = [];

        foreach ($this->fields as $path => $field) {
            if ($field->requiresReview) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * Alle Felder ohne erkannten Wert. Sie bleiben null und erzeugen eine
     * konkrete Pruefaufgabe (Grundsatz 5).
     *
     * @return list<string>
     */
    public function missingPaths(): array
    {
        $paths = [];

        foreach ($this->fields as $path => $field) {
            if ($field->isMissing()) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    public function field(string $path): ?ExtractedValue
    {
        return $this->fields[$path] ?? null;
    }

    /**
     * Validierte Daten oder Ausnahme.
     *
     * Nur fuer Aufrufer, die zwingend validierte Daten benoetigen. Der
     * Regelweg ist die Statuspruefung ueber isValidated().
     *
     * @return array<string, mixed>
     */
    public function requireValidatedData(): array
    {
        if (! $this->isValidated()) {
            throw SchemaValidationFailedException::afterRetries(
                $this->metadata->schemaKey ?? 'unbekannt',
                $this->metadata->attempts,
                $this->violations,
            );
        }

        return $this->data;
    }

    /**
     * Bestaetigt, dass alle temporaeren Providerdateien geloescht sind.
     *
     * Nur fuer Aufrufer, die eine bestaetigte Loeschung erzwingen. Der
     * Regelweg ist das Protokollieren der Loeschstatus.
     */
    public function assertProviderFilesDeleted(): void
    {
        $failed = array_values(array_filter(
            $this->providerFileDeletions,
            static fn (ProviderFileDeletionOutcome $outcome): bool => $outcome->isPrivacyAlert(),
        ));

        if ($failed !== []) {
            throw ProviderFileDeletionFailedException::forOutcomes($this->metadata->providerKey, $failed);
        }
    }

    /**
     * @param  list<ProviderFileDeletionOutcome>  $outcomes
     */
    public function withProviderFileDeletions(array $outcomes): self
    {
        return new self(
            $this->status,
            $this->data,
            $this->fields,
            $this->metadata,
            $this->violations,
            $outcomes,
            $this->conflictReport,
        );
    }

    /**
     * @param  array<string, ExtractedValue>  $fields
     */
    public function withFields(array $fields): self
    {
        return new self(
            $this->status,
            $this->data,
            $fields,
            $this->metadata,
            $this->violations,
            $this->providerFileDeletions,
            $this->conflictReport,
        );
    }

    public function withMetadata(AiCallMetadata $metadata): self
    {
        return new self(
            $this->status,
            $this->data,
            $this->fields,
            $metadata,
            $this->violations,
            $this->providerFileDeletions,
            $this->conflictReport,
        );
    }

    public function withConflict(ConflictReport $report): self
    {
        return new self(
            AiResultStatus::KONFLIKT,
            $this->data,
            $this->fields,
            $this->metadata->withDualReview(),
            $this->violations,
            $this->providerFileDeletions,
            $report,
        );
    }
}
