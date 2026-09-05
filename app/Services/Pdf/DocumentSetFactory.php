<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use App\Enums\GeneratedDocumentVariant;
use App\Services\Pdf\Renderer\OwnerOverviewRenderer;
use App\Services\Pdf\Renderer\TaxBenefitAttachmentRenderer;
use App\Services\Pdf\Renderer\TenantStatementRenderer;
use App\Services\Pdf\View\OwnerOverviewView;
use App\Services\Pdf\View\TenantStatementView;

/**
 * Erzeugt die Dokumente eines Abrechnungslaufs, entweder als Vorschau oder als
 * Finalversion (Abschnitt 14.3).
 *
 * VERBINDLICH: Die Finalversion wird aus demselben gesperrten Calculation
 * Snapshot VOLLSTÄNDIG NEU erzeugt. Es existiert bewusst keine Methode, die
 * eine bestehende Vorschau, deren Bytes, deren Ablagepfad oder deren
 * Dokumenteintrag entgegennimmt. Ein Wasserzeichen wird niemals aus einer
 * bestehenden Datei entfernt; beide Varianten entstehen auf demselben Renderweg
 * und unterscheiden sich ausschließlich in den Wasserzeicheneinstellungen.
 */
final class DocumentSetFactory
{
    public function __construct(
        private readonly TenantStatementRenderer $statements,
        private readonly TaxBenefitAttachmentRenderer $taxBenefit,
        private readonly OwnerOverviewRenderer $ownerOverview,
    ) {}

    /**
     * @param  list<TenantStatementView>  $statementViews
     */
    public function previewSet(
        array $statementViews,
        ?OwnerOverviewView $ownerOverviewView = null,
        ?string $calculationSnapshotId = null,
    ): PdfDocumentSet {
        $statements = [];
        $attachments = [];

        foreach ($statementViews as $view) {
            $statements[] = $this->statements->renderPreview($view, $calculationSnapshotId);

            if ($view->hasTaxBenefitContent()) {
                $attachments[] = $this->taxBenefit->renderPreview($view, $calculationSnapshotId);
            }
        }

        return new PdfDocumentSet(
            GeneratedDocumentVariant::VORSCHAU,
            $statements,
            $attachments,
            $ownerOverviewView instanceof OwnerOverviewView
                ? $this->ownerOverview->renderPreview($ownerOverviewView, $calculationSnapshotId)
                : null,
        );
    }

    /**
     * @param  list<TenantStatementView>  $statementViews
     */
    public function finalSet(
        array $statementViews,
        ?OwnerOverviewView $ownerOverviewView = null,
        ?string $calculationSnapshotId = null,
    ): PdfDocumentSet {
        $statements = [];
        $attachments = [];

        foreach ($statementViews as $view) {
            $statements[] = $this->statements->renderFinal($view, $calculationSnapshotId);

            if ($view->hasTaxBenefitContent()) {
                $attachments[] = $this->taxBenefit->renderFinal($view, $calculationSnapshotId);
            }
        }

        return new PdfDocumentSet(
            GeneratedDocumentVariant::FINAL,
            $statements,
            $attachments,
            $ownerOverviewView instanceof OwnerOverviewView
                ? $this->ownerOverview->renderFinal($ownerOverviewView, $calculationSnapshotId)
                : null,
        );
    }
}
