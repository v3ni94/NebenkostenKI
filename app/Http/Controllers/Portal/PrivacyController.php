<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Application\Account\AuditRecorder;
use App\Application\Account\OrganizationContext;
use App\Application\Privacy\AccountDeletionWorkflow;
use App\Application\Privacy\CreateDataExport;
use App\Application\Privacy\PrivacyDisclosure;
use App\Enums\GeneratedDocumentKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Privacy\DeletionRequestRequest;
use App\Models\GeneratedDocument;
use App\Services\Storage\ArtifactStorage;
use App\Services\Storage\ArtifactType;
use App\Services\Storage\SignedDownloadUrlFactory;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Datenschutzbereich des Kontos (Masterprompt 8.2 und 19).
 *
 * Leistungen:
 *
 *  - Auskunft, welche Daten dauerhaft gespeichert werden und welche nicht,
 *    einschließlich des Hinweises auf die eigene Aufbewahrungspflicht.
 *  - Datenexport nach DSGVO als ZIP, auf Anforderung des Nutzers.
 *  - Löschantrag mit dokumentierter Frist und Rücknahme innerhalb der Frist.
 *
 * MANDANTENSCHUTZ: Es gibt kein implizites Route-Model-Binding. Der Export wird
 * ausschließlich über eine auf den Mandanten gescopte Query geladen und
 * zusätzlich über die Policy geprüft. Ein fremder Export ist damit nicht
 * auffindbar und führt zu 404, ohne seine Existenz zu verraten.
 *
 * AUSLIEFERUNG: Der Export wird über eine autorisierte Streaming-Route
 * ausgeliefert oder über einen kurzlebigen signierten Link. Die Signatur
 * ersetzt die Autorisierung nicht, beide Ebenen greifen zusammen.
 */
class PrivacyController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly AccountDeletionWorkflow $workflow,
        private readonly CreateDataExport $createExport,
        private readonly ArtifactStorage $artifacts,
        private readonly SignedDownloadUrlFactory $signedUrls,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Übersichtsseite im Konto.
     */
    public function show(): View
    {
        $organisation = $this->context->organization();
        $this->authorize('view', $organisation);

        return view('portal.datenschutz.index', [
            'benutzer' => $this->context->user(),
            'organisation' => $organisation,
            'zustand' => $this->workflow->state($this->context->user()),
            'fristTage' => $this->workflow->graceDays(),
            'exporte' => $this->exports(),
            'dauerhaft' => PrivacyDisclosure::storedPermanently(),
            'nichtDauerhaft' => PrivacyDisclosure::neverStoredPermanently(),
            'geloescht' => PrivacyDisclosure::deletedOnAccountDeletion(),
            'erhalten' => PrivacyDisclosure::retainedOnAccountDeletion(),
            'eigenverwahrung' => PrivacyDisclosure::ownRecordsNotice(),
            'verfahrenshinweis' => PrivacyDisclosure::deletionProcessNotice($this->workflow->graceDays()),
        ]);
    }

    /**
     * Datenexport anfordern.
     */
    public function export(): RedirectResponse
    {
        $organisation = $this->context->organization();
        $this->authorize('view', $organisation);

        $ergebnis = ($this->createExport)($this->context->user(), $organisation);

        return redirect()
            ->route('portal.datenschutz.show')
            ->with('status', sprintf(
                'Ihr Datenexport ist erstellt. Das Paket enthält %d Dateien und steht unter '
                .'"Bereitstehende Datenexporte" zum Herunterladen bereit. Es enthält keine Ihrer '
                .'Originaldateien, weil diese nach der Auswertung gelöscht wurden.',
                count($ergebnis->entries),
            ));
    }

    /**
     * Autorisierter Download eines Exports.
     */
    public function download(string $export): StreamedResponse
    {
        $dokument = $this->findExport($export);

        return $this->stream($dokument);
    }

    /**
     * Auslieferung über einen kurzlebigen signierten Link. Die Signatur wird
     * von der Middleware "signed" geprüft, die Zugehörigkeit hier.
     */
    public function signedDownload(string $export): StreamedResponse
    {
        $dokument = $this->findExport($export);

        return $this->stream($dokument);
    }

    /**
     * Kurzlebigen signierten Link erzeugen, etwa zur Weitergabe an ein anderes
     * Gerät des Nutzers.
     */
    public function link(string $export): RedirectResponse
    {
        $dokument = $this->findExport($export);

        $url = $this->signedUrls->forRoute('portal.datenschutz.export.signiert', [
            'export' => (string) $dokument->getKey(),
        ]);

        return redirect()
            ->route('portal.datenschutz.show')
            ->with('status', sprintf(
                'Der Link ist %d Minuten gültig: %s',
                $this->signedUrls->ttlMinutes(),
                $url,
            ));
    }

    /**
     * Löschung des Kontos beantragen.
     */
    public function requestDeletion(DeletionRequestRequest $request): RedirectResponse
    {
        $organisation = $this->context->organization();
        $this->authorize('delete', $organisation);

        $zustand = $this->workflow->request($this->context->user(), $organisation);

        return redirect()
            ->route('portal.datenschutz.show')
            ->with('status', sprintf(
                'Ihr Löschantrag ist aufgenommen. Die endgültige Löschung erfolgt am %s. Bis dahin '
                .'können Sie den Antrag jederzeit zurücknehmen.',
                $zustand->dueAtLabel(),
            ));
    }

    /**
     * Löschantrag innerhalb der Frist zurücknehmen.
     */
    public function withdrawDeletion(): RedirectResponse
    {
        $organisation = $this->context->organization();
        $this->authorize('delete', $organisation);

        $erfolgt = $this->workflow->withdraw($this->context->user(), $organisation);

        if (! $erfolgt) {
            return redirect()
                ->route('portal.datenschutz.show')
                ->with('status', 'Es liegt kein zurücknehmbarer Löschantrag vor. Nach Ablauf der Frist '
                    .'ist eine Rücknahme nicht mehr möglich.');
        }

        return redirect()
            ->route('portal.datenschutz.show')
            ->with('status', 'Ihr Löschantrag ist zurückgenommen. Ihr Konto bleibt unverändert bestehen.');
    }

    /**
     * Bereitstehende Exporte des Mandanten.
     *
     * @return list<GeneratedDocument>
     */
    private function exports(): array
    {
        /** @var list<GeneratedDocument> $exporte */
        $exporte = GeneratedDocument::query()
            ->where('organization_id', $this->context->organizationId())
            ->where('kind', GeneratedDocumentKind::DSGVO_EXPORT->value)
            ->orderByDesc('generated_at')
            ->limit(10)
            ->get()
            ->all();

        return $exporte;
    }

    /**
     * Lädt einen Export ausschließlich über eine gescopte Query.
     */
    private function findExport(string $id): GeneratedDocument
    {
        /** @var GeneratedDocument $dokument */
        $dokument = GeneratedDocument::query()
            ->where('organization_id', $this->context->organizationId())
            ->where('kind', GeneratedDocumentKind::DSGVO_EXPORT->value)
            ->whereKey($id)
            ->firstOrFail();

        return $dokument;
    }

    private function stream(GeneratedDocument $dokument): StreamedResponse
    {
        $pfad = (string) $dokument->getAttribute('storage_path');

        abort_unless($this->artifacts->exists($pfad), 404);

        $this->audit->record(
            action: 'privacy.export.downloaded',
            subject: $dokument,
            actor: $this->context->user(),
            organization: $this->context->organization(),
        );

        return $this->artifacts->download(
            $pfad,
            sprintf('datenexport-%s.zip', now()->format('Y-m-d')),
            ArtifactType::DSGVO_EXPORT->mimeType(),
        );
    }
}
