<?php

declare(strict_types=1);

namespace App\Application\Wizard;

use App\Application\BillingRun\BillingRunProgress;
use App\Application\Calculation\CalculateBillingRun;
use App\Application\Wizard\Dto\PreviewDocumentView;
use App\Enums\GeneratedDocumentKind;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentVariant;
use App\Models\BillingRun;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\Pdf\DocumentSetFactory;
use App\Services\Pdf\PdfDocument;
use App\Services\Pdf\Store\DocumentOwnership;
use App\Services\Pdf\Store\GeneratedDocumentWriter;

/**
 * Schritt 10 des geführten Ablaufs: Vorschau mit Wasserzeichen.
 *
 * VERBINDLICHE REGELN (Masterprompt Abschnitt 9 Schritt 10, 14.3)
 *
 *  1. Alle Mieterabrechnungen und die Eigentümerübersicht werden serverseitig
 *     erzeugt. Das Wasserzeichen ist eingebrannt und keine entfernbare
 *     Browser-Ebene.
 *  2. Ein Download ist in dieser Phase ausschließlich mit Wasserzeichen
 *     möglich.
 *  3. Nach jeder abrechnungsrelevanten Änderung wird die alte Vorschau
 *     ungültig und neu erzeugt. Die Gültigkeit ist an den Calculation Snapshot
 *     gebunden: liegt ein neuer Berechnungsstand vor, ist jede frühere
 *     Vorschau ungültig.
 *  4. Die Finalversion wird niemals durch Entfernen eines Wasserzeichens
 *     erzeugt. Das ist Aufgabe der Finalisierung aus dem gesperrten Snapshot.
 */
final class PreviewBuilder
{
    public function __construct(
        private readonly CalculateBillingRun $calculate,
        private readonly StatementViewFactory $views,
        private readonly DocumentSetFactory $documents,
        private readonly GeneratedDocumentWriter $writer,
        private readonly BillingRunProgress $progress,
    ) {}

    /**
     * Berechnet den Lauf und erzeugt die Vorschau neu.
     *
     * @return list<PreviewDocumentView>
     */
    public function rebuild(BillingRun $billingRun, ?User $actor = null): array
    {
        $outcome = $this->calculate->handle($billingRun, $actor);

        $this->invalidate($billingRun);

        $set = $this->documents->previewSet(
            $this->views->tenantViews($billingRun, $outcome->result, $outcome->assembled),
            $this->views->ownerOverviewView($billingRun, $outcome->result),
            (string) $outcome->snapshot->getKey(),
        );

        $organizationId = $billingRun->getAttribute('organization_id');

        foreach ($set->all() as $document) {
            $this->store($document, is_string($organizationId) ? $organizationId : '', $billingRun);
        }

        // Die Vorschau mit Wasserzeichen liegt vor, deshalb PREVIEW_READY.
        // Erst dieser Status macht den Checkout erreichbar.
        $this->progress->vorschauBereit($billingRun, $actor);

        return $this->current($billingRun->refresh());
    }

    private function store(PdfDocument $document, string $organizationId, BillingRun $billingRun): void
    {
        $this->writer->store(
            $document,
            new DocumentOwnership($organizationId, (string) $billingRun->getKey()),
        );
    }

    /**
     * Setzt alle bestehenden Vorschauen auf ungültig. Die Dateien selbst
     * werden nicht verändert.
     */
    public function invalidate(BillingRun $billingRun): int
    {
        return GeneratedDocument::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('variant', GeneratedDocumentVariant::VORSCHAU->value)
            ->where('status', GeneratedDocumentStatus::AKTIV->value)
            ->update(['status' => GeneratedDocumentStatus::UNGUELTIG->value]);
    }

    /**
     * Gültige Vorschau des aktuellen Berechnungsstands.
     *
     * @return list<PreviewDocumentView>
     */
    public function current(BillingRun $billingRun): array
    {
        $snapshotId = $billingRun->getAttribute('active_calculation_snapshot_id');

        if (! is_string($snapshotId) || $snapshotId === '') {
            return [];
        }

        $documents = GeneratedDocument::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('variant', GeneratedDocumentVariant::VORSCHAU->value)
            ->where('status', GeneratedDocumentStatus::AKTIV->value)
            ->where('calculation_snapshot_id', $snapshotId)
            ->orderBy('kind')
            ->orderBy('generated_at')
            ->get();

        $views = [];
        $nummer = 0;

        foreach ($documents as $document) {
            if ($document->kind === GeneratedDocumentKind::EIGENTUEMERUEBERSICHT) {
                $views[] = new PreviewDocumentView(
                    $document,
                    'Eigentümerübersicht',
                    'Internes Übersichtsblatt, nicht für den Versand an Mieter bestimmt.',
                    $document->page_count ?? 0,
                );

                continue;
            }

            $nummer++;

            $views[] = new PreviewDocumentView(
                $document,
                $document->kind === GeneratedDocumentKind::ANLAGE_35A
                    ? sprintf('Anlage nach § 35a EStG %d', $nummer)
                    : sprintf('Mieterabrechnung %d', $nummer),
                'Vorschau mit Wasserzeichen.',
                $document->page_count ?? 0,
            );
        }

        return $views;
    }

    /**
     * Liegt eine gültige Vorschau zum aktuellen Berechnungsstand vor?
     */
    public function isValid(BillingRun $billingRun): bool
    {
        return $this->current($billingRun) !== [];
    }

    /**
     * Anzahl der Mieterabrechnungen in der gültigen Vorschau. Sie ist die
     * Preiseinheit.
     */
    public function statementCount(BillingRun $billingRun): int
    {
        return GeneratedDocument::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('variant', GeneratedDocumentVariant::VORSCHAU->value)
            ->where('status', GeneratedDocumentStatus::AKTIV->value)
            ->where('kind', GeneratedDocumentKind::MIETERABRECHNUNG->value)
            ->count();
    }
}
