<?php

declare(strict_types=1);

namespace App\Application\Review;

use App\Application\Review\Dto\AnalysisProgress;
use App\Enums\DocumentProcessingStatus;
use App\Models\BillingRun;
use App\Models\CostItem;
use App\Models\Document;
use App\Models\Unit;
use App\Models\ValidationIssue;

/**
 * Fortschritt der automatischen Analyse (Schritt 3).
 *
 * Die Statusseite nennt konkrete Zahlen wie "12 Dokumente geprüft",
 * "3 Einheiten erkannt", "27 Kostenpositionen zugeordnet" und "2 Angaben
 * müssen geprüft werden".
 *
 * Technische Providernamen, Modellnamen und Fehlercodes erscheinen hier
 * bewusst nicht. Sie gehoeren in den internen Bereich.
 */
final class AnalysisProgressReporter
{
    public function report(BillingRun $billingRun): AnalysisProgress
    {
        $total = Document::query()->where('billing_run_id', $billingRun->getKey())->count();

        $evaluated = Document::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('processing_status', DocumentProcessingStatus::ABGESCHLOSSEN->value)
            ->count();

        $failed = Document::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('processing_status', DocumentProcessingStatus::FEHLGESCHLAGEN->value)
            ->count();

        $units = Unit::query()
            ->where('organization_id', $billingRun->getAttribute('organization_id'))
            ->where('property_id', $billingRun->getAttribute('property_id'))
            ->count();

        $costItems = CostItem::query()->where('billing_run_id', $billingRun->getKey())->count();

        $openIssues = ValidationIssue::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->open()
            ->count();

        $blocking = ValidationIssue::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->openBlockers()
            ->count();

        $lines = [
            sprintf('%d von %d Unterlagen ausgewertet', $evaluated, $total),
            sprintf('%d Einheiten erkannt', $units),
            sprintf('%d Kostenpositionen zugeordnet', $costItems),
            sprintf('%d Angaben müssen geprüft werden', $openIssues),
        ];

        if ($failed > 0) {
            $lines[] = sprintf(
                '%d Unterlagen konnten nicht ausgewertet werden. Bitte erfassen Sie die Werte manuell oder laden '
                .'Sie die Unterlagen erneut hoch.',
                $failed
            );
        }

        if ($blocking > 0) {
            $lines[] = sprintf('%d Punkte blockieren die Abrechnung', $blocking);
        }

        return new AnalysisProgress(
            $total,
            $evaluated,
            $failed,
            $units,
            $costItems,
            $openIssues,
            $blocking,
            $total > 0 && $evaluated + $failed >= $total,
            $lines,
        );
    }
}
