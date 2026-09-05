<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Dto\ExtractedValue;

/**
 * Kennzeichnet Felder mit zu geringer Konfidenz als pruefpflichtig.
 *
 * Nach Abschnitt 6.5 ist ab einer Konfidenz unter
 * ai.confidence_review_threshold, Standard 0,80, eine ausdrueckliche Pruefung
 * durch den Nutzer erforderlich. Die Oberflaeche hebt diese Felder gelb
 * hervor.
 *
 * VERBINDLICH: Die Schicht trifft keine stillen Annahmen. Ein Feld unter dem
 * Schwellenwert wird gekennzeichnet, nicht korrigiert, nicht verworfen und
 * nicht durch einen Standardwert ersetzt. Auch ein Feld ohne erkannten Wert
 * bleibt null und wird als pruefpflichtig gekennzeichnet, damit eine konkrete
 * Pruefaufgabe entsteht (Grundsatz 5).
 *
 * Hohe Konfidenz darf den Pruefumfang reduzieren, ersetzt aber nie die
 * abschliessende Gesamtbestaetigung des Nutzers.
 */
final class ConfidenceEvaluator
{
    public function __construct(
        private readonly float $reviewThreshold,
    ) {}

    public function threshold(): float
    {
        return $this->reviewThreshold;
    }

    public function requiresReview(ExtractedValue $field): bool
    {
        if ($field->isMissing()) {
            return true;
        }

        if ($field->confidence < $this->reviewThreshold) {
            return true;
        }

        // Ein Wert ohne Quellenbezug ist nach Grundsatz 2 nicht
        // uebernehmbar und daher immer pruefpflichtig.
        return $field->sourcePage === null;
    }

    /**
     * @param  array<string, ExtractedValue>  $fields
     * @return array<string, ExtractedValue>
     */
    public function mark(array $fields): array
    {
        $marked = [];

        foreach ($fields as $path => $field) {
            $marked[$path] = $field->markRequiresReview($this->requiresReview($field));
        }

        return $marked;
    }

    /**
     * @param  array<string, ExtractedValue>  $fields
     * @return list<string>
     */
    public function reviewRequiredPaths(array $fields): array
    {
        $paths = [];

        foreach ($fields as $path => $field) {
            if ($this->requiresReview($field)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }
}
