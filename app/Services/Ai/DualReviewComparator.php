<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Dto\ConflictEntry;
use App\Services\Ai\Dto\ConflictReport;
use App\Services\Ai\Dto\ExtractedValue;
use App\Services\Ai\Dto\ExtractionResult;

/**
 * Vergleicht zwei Provider-Ergebnisse im Dual-Review-Modus.
 *
 * VERBINDLICH (Abschnitt 13.5): Fachlich widersprechende Ergebnisse werden
 * NICHT automatisch durch einen Mehrheitsentscheid geloest. Sie werden dem
 * Nutzer gezeigt. Diese Klasse hat deshalb bewusst keine Methode, die einen
 * Gewinner bestimmt, und beruecksichtigt die Konfidenz nicht als
 * Entscheidungskriterium. Die Konfidenz wird nur mitgefuehrt, damit die
 * Oberflaeche sie anzeigen kann.
 *
 * Ein Widerspruch liegt vor, wenn zu einem Schemapfad beide Provider einen
 * Wert geliefert haben und die Werte nicht identisch sind, oder wenn genau
 * einer der beiden einen Wert geliefert hat und der andere null. Der zweite
 * Fall ist ebenfalls ein Widerspruch, weil er die Frage aufwirft, ob eine
 * Angabe im Dokument steht.
 */
final class DualReviewComparator
{
    public function compare(ExtractionResult $a, ExtractionResult $b): ConflictReport
    {
        $providerA = $a->metadata->providerKey;
        $providerB = $b->metadata->providerKey;

        $paths = array_values(array_unique([
            ...array_keys($a->fields),
            ...array_keys($b->fields),
        ]));
        sort($paths);

        $entries = [];

        foreach ($paths as $path) {
            $fieldA = $a->fields[$path] ?? null;
            $fieldB = $b->fields[$path] ?? null;

            if (! $this->isConflict($fieldA, $fieldB)) {
                continue;
            }

            $entries[] = new ConflictEntry(
                $path,
                $providerA,
                $fieldA?->value,
                $fieldA?->confidence ?? 0.0,
                $providerB,
                $fieldB?->value,
                $fieldB?->confidence ?? 0.0,
            );
        }

        return new ConflictReport($entries, $providerA, $providerB);
    }

    private function isConflict(?ExtractedValue $a, ?ExtractedValue $b): bool
    {
        if ($a === null && $b === null) {
            return false;
        }

        if ($a === null || $b === null) {
            // Ein Feld, das nur ein Provider ueberhaupt geliefert hat, ist ein
            // Strukturunterschied und damit ein Widerspruch.
            return true;
        }

        return $a->value !== $b->value;
    }
}
