<?php

declare(strict_types=1);

namespace App\Domain\Calculation\Result;

/**
 * Schweregrad eines Prüfergebnisses (Pflichtenheft Abschnitt 9, Schritt 9).
 *
 * BLOCKER  Finalisierung nicht möglich.
 * WARNING  ausdrückliche Nutzerentscheidung erforderlich.
 * INFO     plausibel, aber informativ.
 * PASSED   Prüfschritt erfolgreich.
 */
enum CheckSeverity: string
{
    case BLOCKER = 'BLOCKER';
    case WARNING = 'WARNING';
    case INFO = 'INFO';
    case PASSED = 'PASSED';

    public function blocksFinalization(): bool
    {
        return $this === self::BLOCKER;
    }

    public function label(): string
    {
        return match ($this) {
            self::BLOCKER => 'Blocker',
            self::WARNING => 'Warnung',
            self::INFO => 'Hinweis',
            self::PASSED => 'Bestanden',
        };
    }
}
