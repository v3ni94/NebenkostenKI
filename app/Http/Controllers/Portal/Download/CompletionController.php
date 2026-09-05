<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal\Download;

use App\Application\Account\OrganizationContext;
use App\Application\Wizard\WizardProgress;
use App\Application\Wizard\WizardStep;
use App\Enums\GeneratedDocumentKind;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentVariant;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\BillingRun;
use App\Models\GeneratedDocument;
use App\Models\Invoice;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;

/**
 * Schritt 12: Downloadbereich des abgeschlossenen Abrechnungslaufs.
 *
 * VERBINDLICHE REGELN
 *
 *  1. Angezeigt werden ausschliesslich FINALE, aktive Artefakte des eigenen
 *     Mandanten: die Mieterabrechnungen einzeln, die Anlagen, die
 *     Eigentuemeruebersicht, das ZIP-Paket und die Rechnung der Hausverwaltung
 *     Mueller GmbH.
 *  2. Ersetzte Versionen erscheinen nicht in der Liste. Sie bleiben als Datei
 *     erhalten (Abschnitt 11.5) und werden hier gesondert als Historie
 *     ausgewiesen.
 *  3. Der Abruf selbst laeuft ueber die bestehenden autorisierten Routen
 *     portal.downloads.stream beziehungsweise portal.downloads.signed. Dort ist
 *     die E-Mail-Verifizierung fuer den finalen Download verbindlich; diese
 *     Seite erzeugt keinen eigenen Auslieferungsweg.
 *  4. Der Lauf bleibt dauerhaft im Konto abrufbar.
 */
class CompletionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly WizardProgress $progress,
    ) {}

    public function show(string $billingRun): View
    {
        /** @var BillingRun $lauf */
        $lauf = $this->context->billingRuns()->with('property')->findOrFail($billingRun);

        $this->authorize('view', $lauf);

        $dokumente = $this->finalDocuments($lauf);

        return view('portal.abschluss.index', [
            'lauf' => $lauf,
            'schritt' => WizardStep::ABSCHLUSS,
            'fortschritt' => $this->progress->bar($lauf, WizardStep::ABSCHLUSS),
            'objekt' => $lauf->getRelationValue('property'),
            'abrechnungen' => $this->ofKind($dokumente, GeneratedDocumentKind::MIETERABRECHNUNG),
            'anlagen' => $this->ofKind($dokumente, GeneratedDocumentKind::ANLAGE_35A),
            'uebersichten' => $this->ofKind($dokumente, GeneratedDocumentKind::EIGENTUEMERUEBERSICHT),
            'pakete' => $this->ofKind($dokumente, GeneratedDocumentKind::ZIP_PAKET),
            'rechnungen' => $this->ofKind($dokumente, GeneratedDocumentKind::HVM_RECHNUNG),
            'rechnungsdaten' => $this->invoices($lauf),
            'ersetzt' => $this->replacedDocuments($lauf),
        ]);
    }

    /**
     * @return list<GeneratedDocument>
     */
    private function finalDocuments(BillingRun $billingRun): array
    {
        /** @var list<GeneratedDocument> $documents */
        $documents = GeneratedDocument::query()
            ->where('organization_id', $this->context->organizationId())
            ->where('billing_run_id', $billingRun->getKey())
            ->where('variant', GeneratedDocumentVariant::FINAL->value)
            ->where('status', GeneratedDocumentStatus::AKTIV->value)
            ->orderBy('kind')
            ->orderBy('created_at')
            ->get()
            ->all();

        return $documents;
    }

    /**
     * @return list<GeneratedDocument>
     */
    private function replacedDocuments(BillingRun $billingRun): array
    {
        /** @var list<GeneratedDocument> $documents */
        $documents = GeneratedDocument::query()
            ->where('organization_id', $this->context->organizationId())
            ->where('billing_run_id', $billingRun->getKey())
            ->where('variant', GeneratedDocumentVariant::FINAL->value)
            ->where('status', GeneratedDocumentStatus::ERSETZT->value)
            ->orderByDesc('created_at')
            ->get()
            ->all();

        return $documents;
    }

    /**
     * @param  list<GeneratedDocument>  $documents
     * @return list<GeneratedDocument>
     */
    private function ofKind(array $documents, GeneratedDocumentKind $kind): array
    {
        return array_values(array_filter(
            $documents,
            static fn (GeneratedDocument $document): bool => $document->getAttribute('kind') === $kind,
        ));
    }

    /**
     * @return list<Invoice>
     */
    private function invoices(BillingRun $billingRun): array
    {
        /** @var list<Invoice> $invoices */
        $invoices = Invoice::query()
            ->where('organization_id', $this->context->organizationId())
            ->where('billing_run_id', $billingRun->getKey())
            ->where('status', '!=', InvoiceStatus::ENTWURF->value)
            ->orderBy('issued_on')
            ->orderBy('number')
            ->get()
            ->all();

        return $invoices;
    }
}
