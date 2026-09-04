<?php

declare(strict_types=1);

namespace Tests\Unit\Review;

use App\Application\Review\Dto\AnalysisProgress;
use PHPUnit\Framework\TestCase;

/**
 * Der Prozentwert der Analyse zaehlt alle Endzustaende (Befund N11).
 */
final class AnalysisProgressTest extends TestCase
{
    public function test_nicht_auswertbare_unterlagen_zaehlen_als_beendet(): void
    {
        $fortschritt = new AnalysisProgress(
            documentsTotal: 3,
            documentsEvaluated: 2,
            documentsFailed: 1,
            unitsRecognized: 0,
            costItemsAssigned: 0,
            openChecks: 0,
            blockingChecks: 0,
            complete: true,
        );

        self::assertSame(100, $fortschritt->percent());
    }

    public function test_laufende_unterlagen_zaehlen_nicht(): void
    {
        $fortschritt = new AnalysisProgress(4, 1, 1, 0, 0, 0, 0, false);

        self::assertSame(50, $fortschritt->percent());
    }

    public function test_ohne_unterlagen_null_prozent(): void
    {
        self::assertSame(0, (new AnalysisProgress(0, 0, 0, 0, 0, 0, 0, false))->percent());
    }
}
