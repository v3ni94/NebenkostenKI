<?php

declare(strict_types=1);

namespace App\Services\Ai\Integration;

use App\Enums\ExtractedFieldStatus;
use App\Enums\ValidationIssueStatus;
use App\Enums\ValidationSeverity;
use App\Models\AiCall;
use App\Models\Document;
use App\Models\DocumentPage;
use App\Models\ExtractedField;
use App\Models\ValidationIssue;
use App\Services\Ai\Dto\ExtractedValue;
use App\Services\Ai\Dto\ExtractionResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Persistiert die strukturierten Extraktionsdaten eines Laufs.
 *
 * DIESE KLASSE IST DIE EINZIGE STELLE, DIE AUSGELESENE INHALTE DAUERHAFT
 * SCHREIBT. Sie setzt Abschnitt 6.4 technisch durch.
 *
 * GESPEICHERT WIRD AUSSCHLIESSLICH:
 *   - Schemaschluessel und Schemaversion
 *   - der normalisierte Wert als JSON
 *   - Dokument, Seite und Seitenreferenz
 *   - ein kurzer Fundstellenausschnitt
 *   - Konfidenz und Pruefstatus
 *   - die Referenz auf den KI-Aufruf, ueber die Provider, Modell und
 *     Promptversion nachvollziehbar bleiben
 *
 * NIEMALS GESPEICHERT: vollstaendiger OCR-Text, vollstaendiger Text-Layer,
 * rohe Modellantworten, Base64-Inhalte, Seitenbilder oder Vorschauen. Die
 * Tabelle extracted_fields hat dafuer keine Spalten, und dieser Persister
 * schreibt ausschliesslich die oben genannten Felder.
 *
 * FUNDSTELLENAUSSCHNITT: Die Spalte laesst 500 Zeichen zu, das JSON-Schema
 * begrenzt den Ausschnitt bereits auf 240 Zeichen. Hier wird zusaetzlich und
 * bewusst defensiv auf MAX_SOURCE_EXCERPT_LENGTH gekuerzt, damit auch ein
 * Provider, der die Schemagrenze umgeht, keinen Absatz in die Datenbank
 * bringt. Die Kuerzung ist sichtbar: gekuerzte Ausschnitte enden auf
 * TRUNCATION_MARKER.
 *
 * FEHLENDE WERTE werden niemals geschaetzt (Grundsatz 5). Sie bleiben null und
 * erzeugen eine Pruefaufgabe in validation_issues.
 *
 * IDEMPOTENZ: Ein erneuter Lauf ersetzt vorhandene Felder kontrolliert ueber
 * die Kombination aus Dokument und Schemaschluessel. Eine Nutzerbestaetigung
 * oder Nutzerkorrektur wird dabei nicht ueberschrieben: corrected_value,
 * confirmed_by_user_id, confirmed_at und ein bereits gesetzter Status bleiben
 * erhalten. Felder, die im neuen Lauf nicht mehr vorkommen und noch nicht vom
 * Nutzer angefasst wurden, werden entfernt, damit kein Rest eines frueheren
 * Schemas stehen bleibt.
 */
final class ExtractedFieldPersister
{
    /**
     * Defensive Grenze des gespeicherten Fundstellenausschnitts.
     * Spalte: 500 Zeichen. JSON-Schema: 240 Zeichen. Gespeichert: 200 Zeichen.
     */
    public const MAX_SOURCE_EXCERPT_LENGTH = 200;

    public const TRUNCATION_MARKER = '…';

    /** Regelcode der Pruefaufgabe fuer einen fehlenden Wert. */
    public const RULE_MISSING_VALUE = 'KI-EXT-001';

    /** Regelcode der Pruefaufgabe fuer Felder unterhalb der Konfidenzschwelle. */
    public const RULE_LOW_CONFIDENCE = 'KI-EXT-002';

    /** Regelcode der Pruefaufgabe fuer einen Widerspruch aus dem Dual Review. */
    public const RULE_PROVIDER_CONFLICT = 'KI-EXT-003';

    public const RULE_VERSION = '1.0.0';

    /**
     * Hoechstzahl der Pruefaufgaben je Dokument fuer fehlende Werte. Ein
     * Dokument mit sehr vielen leeren Feldern soll die Aufgabenliste des
     * Nutzers nicht unbrauchbar machen; die restlichen fehlenden Werte sind
     * ueber die Feldliste weiterhin sichtbar.
     */
    public const MAX_MISSING_VALUE_ISSUES = 25;

    public function __construct(private readonly float $confidenceReviewThreshold) {}

    public function threshold(): float
    {
        return $this->confidenceReviewThreshold;
    }

    public function persist(
        Document $document,
        ExtractionResult $result,
        string $schemaKey,
        string $schemaVersion,
        ?AiCall $aiCall,
    ): PersistedExtraction {
        /** @var PersistedExtraction $summary */
        $summary = DB::transaction(function () use ($document, $result, $schemaKey, $schemaVersion, $aiCall): PersistedExtraction {
            $fields = $result->fields;
            $pages = $this->syncPages($document, $fields);

            $written = [];
            $reviewRequired = 0;
            $missing = 0;

            foreach ($fields as $path => $field) {
                $record = $this->writeField($document, $path, $field, $schemaVersion, $pages, $aiCall);

                $written[$path] = $record;

                if ($field->isMissing()) {
                    $missing++;

                    continue;
                }

                if ($this->requiresReview($field)) {
                    $reviewRequired++;
                }
            }

            $this->removeStaleFields($document, array_keys($written));
            $this->updatePageCounters($document, $pages);

            $issues = $this->writeValidationIssues($document, $result, $schemaKey, $written);

            return new PersistedExtraction(
                count($written),
                $this->pageCountFor($document, $pages),
                $reviewRequired,
                $missing,
                $issues,
            );
        });

        return $summary;
    }

    /**
     * Ein Feld ist pruefpflichtig, wenn es unter der konfigurierten
     * Konfidenzschwelle liegt oder keinen Quellenbezug traegt. Der Wert wird
     * dabei nicht veraendert und nicht verworfen, er wird nur gekennzeichnet.
     */
    public function requiresReview(ExtractedValue $field): bool
    {
        if ($field->isMissing()) {
            return true;
        }

        if ($field->confidence < $this->confidenceReviewThreshold) {
            return true;
        }

        return $field->sourcePage === null;
    }

    /**
     * Kuerzt einen Fundstellenausschnitt defensiv.
     */
    public function truncateExcerpt(?string $excerpt): ?string
    {
        if ($excerpt === null) {
            return null;
        }

        $normalized = trim(preg_replace('/\s+/u', ' ', $excerpt) ?? $excerpt);

        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized) <= self::MAX_SOURCE_EXCERPT_LENGTH) {
            return $normalized;
        }

        return mb_substr($normalized, 0, self::MAX_SOURCE_EXCERPT_LENGTH - 1).self::TRUNCATION_MARKER;
    }

    // -----------------------------------------------------------------
    // Felder
    // -----------------------------------------------------------------

    /**
     * @param  array<int, DocumentPage>  $pages
     */
    private function writeField(
        Document $document,
        string $path,
        ExtractedValue $field,
        string $schemaVersion,
        array $pages,
        ?AiCall $aiCall,
    ): ExtractedField {
        $existing = ExtractedField::query()
            ->where('document_id', $document->getKey())
            ->where('schema_key', $path)
            ->first();

        $page = $field->sourcePage !== null ? ($pages[$field->sourcePage] ?? null) : null;

        $attributes = [
            'organization_id' => $document->getAttribute('organization_id'),
            'billing_run_id' => $document->getAttribute('billing_run_id'),
            'document_id' => $document->getKey(),
            'document_page_id' => $page?->getKey(),
            'schema_key' => mb_substr($path, 0, 190),
            'schema_version' => mb_substr($schemaVersion, 0, 32),
            // Wert als JSON. Die Spalte ist als Array gecastet, deshalb wird
            // der normalisierte Wert in einer festen Huelle abgelegt.
            'value' => ['wert' => $field->value],
            'page_number' => $field->sourcePage,
            'source_excerpt' => $this->truncateExcerpt($field->sourceExcerpt),
            'confidence' => $this->confidenceFor($field),
            'ai_call_id' => $aiCall?->getKey(),
        ];

        if (! $existing instanceof ExtractedField) {
            $record = new ExtractedField;

            $record->fill($attributes + ['status' => ExtractedFieldStatus::AUTOMATISCH_ERKANNT]);
            $record->save();

            return $record;
        }

        // Eine Nutzerentscheidung wird nicht zurueckgesetzt. Der maschinelle
        // Wert wird aktualisiert, die Korrektur und die Bestaetigung bleiben.
        $status = $existing->getAttribute('status');

        if ($status === ExtractedFieldStatus::AUTOMATISCH_ERKANNT) {
            $attributes['status'] = ExtractedFieldStatus::AUTOMATISCH_ERKANNT;
        }

        $existing->forceFill($attributes)->save();

        return $existing;
    }

    /**
     * Ein fehlender Wert hat keine belastbare Konfidenz. Die Spalte bleibt
     * null, damit kein Nullwert als gemessene Sicherheit missverstanden wird.
     */
    private function confidenceFor(ExtractedValue $field): ?string
    {
        if ($field->isMissing()) {
            return null;
        }

        return number_format(max(0.0, min(1.0, $field->confidence)), 4, '.', '');
    }

    /**
     * @param  list<string>  $keptPaths
     */
    private function removeStaleFields(Document $document, array $keptPaths): void
    {
        $query = ExtractedField::query()
            ->where('document_id', $document->getKey())
            ->where('status', ExtractedFieldStatus::AUTOMATISCH_ERKANNT->value)
            ->whereNull('corrected_value');

        if ($keptPaths !== []) {
            $query->whereNotIn('schema_key', $keptPaths);
        }

        $query->delete();
    }

    // -----------------------------------------------------------------
    // Seiten
    // -----------------------------------------------------------------

    /**
     * @param  array<string, ExtractedValue>  $fields
     * @return array<int, DocumentPage>
     */
    private function syncPages(Document $document, array $fields): array
    {
        $pageNumbers = [];

        foreach ($fields as $field) {
            if ($field->sourcePage !== null && $field->sourcePage >= 1) {
                $pageNumbers[$field->sourcePage] = true;
            }
        }

        $pages = [];

        foreach (array_keys($pageNumbers) as $number) {
            $page = DocumentPage::query()
                ->where('document_id', $document->getKey())
                ->where('page_number', $number)
                ->first();

            if (! $page instanceof DocumentPage) {
                $page = new DocumentPage;

                $page->fill([
                    'organization_id' => $document->getAttribute('organization_id'),
                    'document_id' => $document->getKey(),
                    'page_number' => $number,
                    'has_structured_findings' => true,
                    'extracted_field_count' => 0,
                ]);

                $page->save();
            }

            $pages[$number] = $page;
        }

        return $pages;
    }

    /**
     * @param  array<int, DocumentPage>  $pages
     */
    private function updatePageCounters(Document $document, array $pages): void
    {
        foreach ($pages as $page) {
            $count = ExtractedField::query()
                ->where('document_id', $document->getKey())
                ->where('document_page_id', $page->getKey())
                ->count();

            $page->forceFill([
                'extracted_field_count' => $count,
                'has_structured_findings' => $count > 0,
            ])->save();
        }
    }

    /**
     * @param  array<int, DocumentPage>  $pages
     */
    private function pageCountFor(Document $document, array $pages): ?int
    {
        $known = $document->getAttribute('page_count');

        if (is_int($known) && $known > 0) {
            return $known;
        }

        if ($pages === []) {
            return null;
        }

        return max(array_keys($pages));
    }

    // -----------------------------------------------------------------
    // Pruefaufgaben
    // -----------------------------------------------------------------

    /**
     * @param  array<string, ExtractedField>  $written
     */
    private function writeValidationIssues(
        Document $document,
        ExtractionResult $result,
        string $schemaKey,
        array $written,
    ): int {
        $this->clearOwnIssues($document);

        $count = 0;
        $missingPaths = [];
        $reviewPaths = [];

        foreach ($result->fields as $path => $field) {
            if ($field->isMissing()) {
                $missingPaths[] = $path;

                continue;
            }

            if ($this->requiresReview($field)) {
                $reviewPaths[] = $path;
            }
        }

        foreach (array_slice($missingPaths, 0, self::MAX_MISSING_VALUE_ISSUES) as $path) {
            $record = $written[$path] ?? null;

            $this->createIssue(
                $document,
                self::RULE_MISSING_VALUE,
                ValidationSeverity::WARNUNG,
                sprintf('Fehlender Wert: %s', $path),
                sprintf(
                    'Aus %s konnte für das Feld "%s" (Schema %s) kein Wert ausgelesen werden. '
                    .'Der Wert wurde bewusst nicht geschätzt. Bitte tragen Sie ihn manuell nach.',
                    $this->label($document),
                    $path,
                    $schemaKey,
                ),
                $record instanceof ExtractedField ? ExtractedField::class : null,
                $record instanceof ExtractedField ? (string) $record->getKey() : null,
            );

            $count++;
        }

        if ($reviewPaths !== []) {
            $this->createIssue(
                $document,
                self::RULE_LOW_CONFIDENCE,
                ValidationSeverity::WARNUNG,
                sprintf('%d Wert(e) mit geringer Konfidenz prüfen', count($reviewPaths)),
                sprintf(
                    'Für %s liegen %d ausgelesene Werte unter der Konfidenzschwelle von %s oder ohne Seitenbezug vor. '
                    .'Bitte prüfen Sie diese Felder ausdrücklich: %s.',
                    $this->label($document),
                    count($reviewPaths),
                    number_format($this->confidenceReviewThreshold, 2, ',', '.'),
                    implode(', ', array_slice($reviewPaths, 0, 40)),
                ),
                Document::class,
                (string) $document->getKey(),
            );

            $count++;
        }

        if ($result->hasConflict()) {
            $this->createIssue(
                $document,
                self::RULE_PROVIDER_CONFLICT,
                ValidationSeverity::BLOCKER,
                'Widersprüchliche Auswertungsergebnisse',
                sprintf(
                    'Für %s haben zwei Auswertungsdienste unterschiedliche Werte geliefert. '
                    .'Es wird kein Mehrheitsentscheid getroffen. Bitte prüfen und bestätigen Sie die betroffenen Felder: %s.',
                    $this->label($document),
                    implode(', ', array_slice($result->conflictReport?->paths() ?? [], 0, 40)),
                ),
                Document::class,
                (string) $document->getKey(),
                true,
            );

            $count++;
        }

        return $count;
    }

    private function clearOwnIssues(Document $document): void
    {
        ValidationIssue::query()
            ->where('billing_run_id', $document->getAttribute('billing_run_id'))
            ->whereIn('rule_code', [
                self::RULE_MISSING_VALUE,
                self::RULE_LOW_CONFIDENCE,
                self::RULE_PROVIDER_CONFLICT,
            ])
            ->where('status', ValidationIssueStatus::OFFEN->value)
            ->where(function ($query) use ($document): void {
                $query->where(function ($inner) use ($document): void {
                    $inner->where('entity_type', Document::class)
                        ->where('entity_id', $document->getKey());
                })->orWhereIn(
                    'entity_id',
                    ExtractedField::query()
                        ->where('document_id', $document->getKey())
                        ->select('id')
                );
            })
            ->delete();
    }

    private function createIssue(
        Document $document,
        string $ruleCode,
        ValidationSeverity $severity,
        string $title,
        string $description,
        ?string $entityType,
        ?string $entityId,
        bool $blocksFinalization = false,
    ): void {
        $issue = new ValidationIssue;

        $issue->fill([
            'organization_id' => $document->getAttribute('organization_id'),
            'billing_run_id' => $document->getAttribute('billing_run_id'),
            'rule_code' => mb_substr($ruleCode, 0, 80),
            'rule_version' => self::RULE_VERSION,
            'severity' => $severity,
            'status' => ValidationIssueStatus::OFFEN,
            'blocks_finalization' => $blocksFinalization,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'title' => mb_substr($title, 0, 190),
            'description' => $description,
            'detected_at' => Carbon::now(),
        ]);

        $issue->save();
    }

    /**
     * Neutrale Quellenbezeichnung. Niemals der Originaldateiname.
     */
    private function label(Document $document): string
    {
        $label = $document->getAttribute('source_label');

        return is_string($label) && $label !== '' ? $label : 'der Unterlage';
    }
}
