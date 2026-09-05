<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Enums\DocumentRelationType;
use App\Models\Document;
use App\Models\DocumentRelation;

/**
 * Use Case: Dubletten erkennen.
 *
 * VERBINDLICH: Die Erkennung laeuft ausschliesslich ueber den
 * schluesselgebundenen HMAC-SHA-256-Fingerabdruck. Ein reiner SHA-256 des
 * Inhalts wird nicht dauerhaft gespeichert, weil er ein Wiedererkennungsmerkmal
 * der geloeschten Originaldatei waere (siehe FingerprintFactory,
 * ARCHITECTURE.md Abschnitt 5.2).
 *
 * Verglichen wird innerhalb desselben Abrechnungslaufs. Ein laufuebergreifender
 * Vergleich waere fachlich falsch, weil dieselbe Unterlage in zwei Jahren
 * zulaessig zweimal vorkommen kann, und datenschutzrechtlich unnoetig.
 */
final class DetectDuplicate
{
    /**
     * Findet das aeltere Dokument mit gleichem Fingerabdruck, oder null.
     */
    public function findOriginal(Document $document): ?Document
    {
        $fingerprint = $document->getAttribute('fingerprint_hmac');

        if (! is_string($fingerprint) || $fingerprint === '') {
            return null;
        }

        $candidate = Document::query()
            ->where('billing_run_id', $document->getAttribute('billing_run_id'))
            ->where('fingerprint_hmac', $fingerprint)
            ->whereKeyNot($document->getKey())
            ->whereNull('duplicate_of_document_id')
            ->orderBy('sequence_number')
            ->first();

        return $candidate instanceof Document ? $candidate : null;
    }

    /**
     * Vermerkt die Dublette am Dokument und als nachvollziehbare Beziehung.
     * Es wird nichts stillschweigend verworfen; der Nutzer sieht den Hinweis in
     * der Statusliste.
     */
    public function markAsDuplicate(Document $document, Document $original): void
    {
        $document->forceFill([
            'duplicate_of_document_id' => $original->getKey(),
        ])->save();

        $exists = DocumentRelation::query()
            ->where('from_document_id', $document->getKey())
            ->where('to_document_id', $original->getKey())
            ->where('relation_type', DocumentRelationType::DUBLETTE->value)
            ->exists();

        if ($exists) {
            return;
        }

        $relation = new DocumentRelation;

        $relation->fill([
            'organization_id' => $document->getAttribute('organization_id'),
            'billing_run_id' => $document->getAttribute('billing_run_id'),
            'from_document_id' => $document->getKey(),
            'to_document_id' => $original->getKey(),
            'relation_type' => DocumentRelationType::DUBLETTE,
            'confidence' => '1.0000',
            'note' => sprintf(
                'Gleicher Fingerabdruck wie %s.',
                (string) $original->getAttribute('source_label')
            ),
        ]);

        $relation->save();
    }
}
