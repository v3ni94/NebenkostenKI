<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Application\Account\AuditRecorder;
use App\Application\BillingRun\BillingRunStateMachine;
use App\Application\Payment\Contracts\FinalDocumentViews;
use App\Application\Payment\Dto\FinalizationResult;
use App\Application\Payment\Dto\PriceQuote;
use App\Application\Payment\Events\BillingRunFinalized;
use App\Application\Payment\Exceptions\CustomerAddressMissingException;
use App\Application\Payment\Exceptions\FinalizationFailedException;
use App\Application\Payment\Exceptions\OperatorMasterdataMissingException;
use App\Enums\BillingRunStatus;
use App\Enums\CalculationSnapshotStatus;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentVariant;
use App\Enums\PaymentStatus;
use App\Enums\UnitStatementStatus;
use App\Models\BillingRun;
use App\Models\CalculationSnapshot;
use App\Models\GeneratedDocument;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\UnitStatement;
use App\Models\User;
use App\Services\Pdf\DocumentSetFactory;
use App\Services\Pdf\PdfDocument;
use App\Services\Pdf\Store\DocumentOwnership;
use App\Services\Pdf\Store\DocumentPackageBuilder;
use App\Services\Pdf\Store\GeneratedDocumentWriter;
use App\Services\Storage\ArtifactStorage;
use App\Services\Storage\ArtifactType;
use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Use Case: Finalisierung nach bestaetigter Zahlung (Schritt 12).
 *
 * VERBINDLICHE REIHENFOLGE
 *
 *  1. Bestaetigte Zahlung pruefen. Ohne sie wird nichts erzeugt. Freigeschaltet
 *     wird ausschliesslich durch den signaturgeprueften Webhook; der
 *     Browser-Redirect erreicht diesen Use Case nicht.
 *  2. Bezahlten Calculation Snapshot sperren.
 *  3. Alle PDFs OHNE Wasserzeichen VOLLSTAENDIG NEU aus dem gesperrten
 *     Snapshot erzeugen. Es wird ausdruecklich kein Wasserzeichen aus einer
 *     bestehenden Vorschaudatei entfernt; der Weg fuehrt ueber
 *     DocumentSetFactory::finalSet(), das keine Vorschaudatei annehmen kann.
 *  4. Je Datei SHA-256, Groesse, Seitenzahl und Templateversion speichern.
 *  5. Rechnung der Hausverwaltung Mueller GmbH erzeugen.
 *  6. Einzel-PDFs und ZIP-Paket bereitstellen.
 *  7. Lauf auf FINALIZED setzen und das Ereignis BillingRunFinalized
 *     ausloesen. Der Versand der Bestaetigungsmail haengt an diesem Ereignis
 *     und gehoert nicht zu diesem Paket.
 *
 * KORREKTUR NACH ZAHLUNG (Abschnitt 11.5): Ein finalisiertes PDF wird niemals
 * ueberschrieben. Sind bereits Final-Dokumente vorhanden, entsteht eine neue
 * Version; die alten Eintraege werden ueber
 * GeneratedDocumentWriter::markReplaced() auf ERSETZT gesetzt und bleiben mit
 * ihrer Datei erhalten.
 *
 * FEHLENDE PFLICHTANGABEN DES BETREIBERS (Abschnitt 15.2): Fehlen Steuer- oder
 * Bankdaten oder sind die Stammdaten nicht bestaetigt, wird KEINE Rechnung
 * festgeschrieben und keine Rechnungsnummer verbraucht. Die bezahlten
 * Abrechnungen werden dennoch vollstaendig erzeugt und bereitgestellt: Der
 * Kunde hat bezahlt, und ein Zurueckhalten der Leistung waere weder fachlich
 * noch kaufmaennisch vertretbar. Der Blockerzustand wird protokolliert und ist
 * ueber OperatorInvoiceBlocker abfragbar; die Rechnung ist nach Ergaenzung der
 * Angaben nachzuholen. Dasselbe gilt fuer eine fehlende Rechnungsanschrift des
 * Kunden (CustomerAddressMissingException): keine Rechnung, keine Nummer, der
 * Lauf erscheint im Zahlungsnachlauf unter "ohne Rechnung".
 */
final class FinalizeBillingRun
{
    public function __construct(
        private readonly DocumentSetFactory $documents,
        private readonly GeneratedDocumentWriter $writer,
        private readonly DocumentPackageBuilder $packages,
        private readonly IssueOperatorInvoice $invoices,
        private readonly OperatorInvoiceBlocker $blocker,
        private readonly BillingRunStateMachine $stateMachine,
        private readonly AuditRecorder $audit,
        private readonly Container $container,
        private readonly ArtifactStorage $artifacts = new ArtifactStorage,
    ) {}

    /**
     * @throws FinalizationFailedException
     */
    public function __invoke(BillingRun $billingRun, ?User $actor = null): FinalizationResult
    {
        $payment = $this->confirmedPayment($billingRun);
        $snapshot = $this->lockedSnapshot($billingRun);

        $this->claim($billingRun, $actor);

        try {
            $result = $this->produce($billingRun, $payment, $snapshot, $actor);
        } catch (Throwable $exception) {
            $billingRun->forceFill([
                'failure_code' => 'FINALISIERUNG_FEHLGESCHLAGEN',
                'failure_message' => mb_substr($exception->getMessage(), 0, 500),
            ])->save();

            $this->stateMachine->transitionTo($billingRun, BillingRunStatus::FAILED, $actor);

            throw $exception;
        }

        $this->stateMachine->transitionTo($billingRun, BillingRunStatus::FINALIZED, $actor, [
            'dokumente' => $result->documentCount(),
            'rechnung' => $result->invoice instanceof Invoice
                ? (string) $result->invoice->getAttribute('number')
                : null,
        ]);

        event(new BillingRunFinalized(
            $billingRun->refresh(),
            $payment,
            array_values(array_map(
                static fn (GeneratedDocument $document): string => (string) $document->getKey(),
                $result->documents,
            )),
            $result->package instanceof GeneratedDocument ? (string) $result->package->getKey() : null,
            $result->invoice,
            $result->invoiceIsBlocked(),
        ));

        return $result;
    }

    /**
     * Beansprucht den Lauf atomar fuer genau einen Finalisierer.
     *
     * Admin-POST, Zeitplan und Webhook koennen denselben bezahlten Lauf
     * gleichzeitig finalisieren wollen. Der Lauf wird deshalb in einer
     * Transaktion mit Zeilensperre neu geladen, sein tatsaechlicher Stand
     * geprueft und der Uebergang nach FINALIZING in derselben Transaktion
     * gesetzt. Der zweite Aufrufer wartet an der Sperre, liest danach
     * FINALIZING und erhaelt "bereits in Bearbeitung", ohne ein zweites Mal
     * Dokumente zu erzeugen.
     *
     * @throws FinalizationFailedException
     */
    private function claim(BillingRun $billingRun, ?User $actor): void
    {
        DB::transaction(function () use ($billingRun, $actor): void {
            /** @var BillingRun $locked */
            $locked = BillingRun::query()
                ->whereKey($billingRun->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->getAttribute('status') === BillingRunStatus::FINALIZING) {
                throw FinalizationFailedException::alreadyInProgress();
            }

            $this->stateMachine->transitionTo($locked, BillingRunStatus::FINALIZING, $actor);
        });

        // Der uebergebene Lauf uebernimmt den festgeschriebenen Stand.
        $billingRun->refresh();
    }

    /**
     * @throws FinalizationFailedException
     */
    private function produce(
        BillingRun $billingRun,
        Payment $payment,
        CalculationSnapshot $snapshot,
        ?User $actor,
    ): FinalizationResult {
        $bundle = $this->views()->forSnapshot($snapshot);

        if ($bundle->isEmpty()) {
            throw FinalizationFailedException::withoutStatements();
        }

        $organizationId = (string) $billingRun->getAttribute('organization_id');
        $snapshotId = (string) $snapshot->getKey();

        // Frueher erzeugte Final-Dokumente bleiben als Datei erhalten und
        // werden nur auf ERSETZT gesetzt (Abschnitt 11.5).
        $previous = $this->activeFinalDocuments($billingRun);

        $set = $this->documents->finalSet($bundle->statements, $bundle->ownerOverview, $snapshotId);

        $stored = [];
        $pdfDocuments = [];

        foreach ($set->statements as $index => $document) {
            $pdfDocuments[] = $document;
            $stored[] = $this->writer->store($document, new DocumentOwnership(
                $organizationId,
                (string) $billingRun->getKey(),
                $bundle->statementIdAt($index),
            ))->record;
        }

        foreach ($set->taxBenefitAttachments as $document) {
            $pdfDocuments[] = $document;
            $stored[] = $this->writer->store($document, new DocumentOwnership(
                $organizationId,
                (string) $billingRun->getKey(),
            ))->record;
        }

        if ($set->ownerOverview instanceof PdfDocument) {
            $pdfDocuments[] = $set->ownerOverview;
            $stored[] = $this->writer->store($set->ownerOverview, new DocumentOwnership(
                $organizationId,
                (string) $billingRun->getKey(),
            ))->record;
        }

        $invoice = null;
        $blockers = [];

        try {
            $invoice = ($this->invoices)($billingRun, $payment, $this->paidQuote($payment), $actor);
        } catch (OperatorMasterdataMissingException|CustomerAddressMissingException $exception) {
            $blockers = $exception instanceof CustomerAddressMissingException
                ? [CustomerAddressMissingException::BLOCKER]
                : $this->blocker->missingFields();

            $this->audit->record(
                action: 'invoice.blocked',
                subject: $billingRun,
                actor: $actor,
                organization: $organizationId,
                metadata: ['fehlende_angaben' => implode(', ', $blockers)],
                reason: $exception->getMessage(),
            );
        }

        if ($invoice instanceof Invoice) {
            $invoicePdf = $this->invoicePdf($invoice);

            if ($invoicePdf instanceof PdfDocument) {
                $pdfDocuments[] = $invoicePdf;
            }
        }

        $package = $this->buildPackage($pdfDocuments, $organizationId, $billingRun, $snapshotId);

        $this->markStatementsFinal($billingRun);
        $this->markReplaced($previous, $stored);

        return new FinalizationResult($stored, $package, $invoice, $blockers);
    }

    /**
     * Preisstand der TATSAECHLICH bestaetigten Zahlung.
     *
     * Die Rechnung stellt genau den bezahlten Betrag, nicht den derzeit
     * konfigurierten Preis. Aendert der Betreiber den Preis zwischen Zahlung und
     * Finalisierung, bleibt die Rechnung damit richtig. Netto und Steuer werden
     * je Einzelpreis aus dem bezahlten Brutto zurueckgerechnet (ADR-010).
     *
     * Oeffentlich, damit eine nachgeholte Rechnung (IssueMissingInvoice)
     * denselben Preisstand verwendet wie die Finalisierung.
     */
    public function paidQuote(Payment $payment): PriceQuote
    {
        $gross = (int) $payment->getAttribute('amount_cent');
        $count = max(1, (int) $payment->getAttribute('statement_count'));
        $base = (int) ($payment->getAttribute('base_price_gross_cent') ?? 0);
        $unit = (int) ($payment->getAttribute('unit_price_gross_cent') ?? 0);

        if ($unit <= 0) {
            $unit = intdiv(max(0, $gross - $base), $count);
        }

        $rate = $this->vatRatePercent($payment);
        $quote = PriceQuote::fromGrossComponents(
            $count,
            $unit,
            $base,
            $rate,
            strtolower((string) $payment->getAttribute('currency')),
        );

        if ($quote->grossCent === $gross) {
            return $quote;
        }

        // Der gespeicherte Einzelpreis passt nicht zum bezahlten Betrag
        // (Altdaten). Massgeblich ist der bezahlte Bruttobetrag. Der
        // Einzelpreis wird aus ihm abgeleitet und die Zerlegung wieder je
        // Einzelpreis gebildet (ADR-010), damit auf der Rechnung Anzahl mal
        // Nettoeinzelpreis den Positionsnettobetrag ergibt. Bleibt ein
        // Cent-Rest, der sich nicht auf die Einzelpreise verteilen laesst,
        // liegt er ausschliesslich in der Steuerzeile.
        $unit = intdiv(max(0, $gross - $base), $count);
        $quote = PriceQuote::fromGrossComponents(
            $count,
            $unit,
            $base,
            $rate,
            strtolower((string) $payment->getAttribute('currency')),
        );

        if ($quote->grossCent === $gross) {
            return $quote;
        }

        return new PriceQuote(
            $count,
            $unit,
            $base,
            $gross,
            $quote->netCent,
            $gross - $quote->netCent,
            $quote->vatRatePercent,
            strtolower((string) $payment->getAttribute('currency')),
        );
    }

    /**
     * Steuersatz des Zahlungszeitpunkts. Er steht auf dem Abrechnungslauf,
     * damit ein spaeterer Satzwechsel eine alte Rechnung nicht verfaelscht.
     */
    private function vatRatePercent(Payment $payment): string
    {
        $billingRun = $payment->getRelationValue('billingRun');
        $rate = is_object($billingRun) ? $billingRun->getAttribute('vat_rate_percent') : null;

        if (is_string($rate) && trim($rate) !== '' && (float) $rate > 0) {
            return $rate;
        }

        $configured = config('smartabrechnen.pricing.vat_rate_percent');

        return is_int($configured) ? (string) $configured : '19';
    }

    /**
     * ZIP-Paket mit allen finalen Dateien des Laufs.
     *
     * @param  list<PdfDocument>  $documents
     */
    private function buildPackage(
        array $documents,
        string $organizationId,
        BillingRun $billingRun,
        string $snapshotId,
    ): ?GeneratedDocument {
        if ($documents === []) {
            return null;
        }

        $paket = $this->packages->build($documents);

        $document = new PdfDocument(
            $this->packages->artifactType(),
            GeneratedDocumentVariant::FINAL,
            $paket['contents'],
            count($paket['entries']),
            (string) (config('smartabrechnen.pdf.template_version') ?? '1.0.0'),
            new DateTimeImmutable,
            $snapshotId,
            sprintf('Abrechnung-%s-final.zip', (string) $billingRun->getAttribute('billing_year')),
        );

        return $this->writer->store($document, new DocumentOwnership(
            $organizationId,
            (string) $billingRun->getKey(),
        ))->record;
    }

    /**
     * Die erzeugte Rechnung als Bytefolge fuer das ZIP-Paket. Gelesen wird die
     * bereits gespeicherte Datei; sie wird nicht erneut gerendert.
     */
    private function invoicePdf(Invoice $invoice): ?PdfDocument
    {
        $record = GeneratedDocument::query()
            ->where('invoice_id', $invoice->getKey())
            ->where('status', GeneratedDocumentStatus::AKTIV->value)
            ->orderByDesc('created_at')
            ->first();

        if (! $record instanceof GeneratedDocument) {
            return null;
        }

        $path = $record->getAttribute('storage_path');
        $contents = is_string($path) ? $this->artifacts->get($path) : null;

        if (! is_string($contents) || $contents === '') {
            return null;
        }

        $pageCount = $record->getAttribute('page_count');

        return new PdfDocument(
            ArtifactType::HVM_RECHNUNG,
            GeneratedDocumentVariant::FINAL,
            $contents,
            is_int($pageCount) ? $pageCount : 1,
            (string) $record->getAttribute('template_version'),
            new DateTimeImmutable,
            null,
            sprintf('Rechnung-%s.pdf', (string) $invoice->getAttribute('number')),
        );
    }

    /**
     * Aktive Final-Dokumente des Laufs OHNE die Rechnungsbelege. Die Rechnung
     * der Hausverwaltung wird bei einer erneuten Finalisierung nicht neu
     * erzeugt (IssueOperatorInvoice ist idempotent) und darf deshalb nicht als
     * ersetzt markiert werden; sie ist ein aufzubewahrender Beleg.
     *
     * @return list<GeneratedDocument>
     */
    private function activeFinalDocuments(BillingRun $billingRun): array
    {
        /** @var list<GeneratedDocument> $documents */
        $documents = GeneratedDocument::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('variant', GeneratedDocumentVariant::FINAL->value)
            ->where('status', GeneratedDocumentStatus::AKTIV->value)
            ->whereNull('invoice_id')
            ->get()
            ->all();

        return $documents;
    }

    /**
     * @param  list<GeneratedDocument>  $previous
     * @param  list<GeneratedDocument>  $current
     */
    private function markReplaced(array $previous, array $current): void
    {
        if ($previous === [] || $current === []) {
            return;
        }

        foreach ($previous as $index => $old) {
            $replacement = $current[$index] ?? $current[0];

            $this->writer->markReplaced($old, $replacement);
        }
    }

    /**
     * FINAL wird ausschliesslich der bezahlte Berechnungsstand. Abrechnungen
     * frueherer Staende, die beim Neurechnen nicht ersetzt wurden, weil ihr
     * Mietverhaeltnis im Ergebnis nicht mehr vorkommt, gelten als ersetzt: sie
     * wurden weder bezahlt noch ausgeliefert.
     */
    private function markStatementsFinal(BillingRun $billingRun): void
    {
        $snapshotId = (string) $billingRun->getAttribute('active_calculation_snapshot_id');

        UnitStatement::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('calculation_snapshot_id', '!=', $snapshotId)
            ->whereNotIn('status', [UnitStatementStatus::ERSETZT->value, UnitStatementStatus::FINAL->value])
            ->update(['status' => UnitStatementStatus::ERSETZT->value]);

        UnitStatement::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('calculation_snapshot_id', $snapshotId)
            ->where('status', '!=', UnitStatementStatus::ERSETZT->value)
            ->update(['status' => UnitStatementStatus::FINAL->value]);
    }

    /**
     * @throws FinalizationFailedException
     */
    private function confirmedPayment(BillingRun $billingRun): Payment
    {
        $payment = Payment::query()
            ->where('billing_run_id', $billingRun->getKey())
            ->where('status', PaymentStatus::BEZAHLT->value)
            ->orderByDesc('paid_at')
            ->first();

        if (! $payment instanceof Payment || $billingRun->getAttribute('paid_at') === null) {
            throw FinalizationFailedException::paymentNotConfirmed();
        }

        return $payment;
    }

    /**
     * Sperrt den bezahlten Berechnungsstand. Ein gesperrter Stand wird nicht
     * erneut geschrieben.
     *
     * @throws FinalizationFailedException
     */
    private function lockedSnapshot(BillingRun $billingRun): CalculationSnapshot
    {
        $id = $billingRun->getAttribute('active_calculation_snapshot_id');

        $snapshot = is_string($id) ? CalculationSnapshot::query()->find($id) : null;

        if (! $snapshot instanceof CalculationSnapshot) {
            throw FinalizationFailedException::snapshotMissing();
        }

        if ($snapshot->getAttribute('locked_at') === null) {
            $snapshot->forceFill([
                'status' => CalculationSnapshotStatus::GESPERRT,
                'locked_at' => now(),
            ])->save();
        }

        return $snapshot;
    }

    /**
     * @throws FinalizationFailedException
     */
    private function views(): FinalDocumentViews
    {
        if (! $this->container->bound(FinalDocumentViews::class)) {
            throw FinalizationFailedException::viewsUnavailable();
        }

        $views = $this->container->make(FinalDocumentViews::class);

        if (! $views instanceof FinalDocumentViews) {
            throw FinalizationFailedException::viewsUnavailable();
        }

        return $views;
    }
}
