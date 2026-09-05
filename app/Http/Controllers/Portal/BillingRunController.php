<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Application\Account\AuditRecorder;
use App\Application\Account\OrganizationContext;
use App\Application\BillingRun\BillingRunStateMachine;
use App\Application\BillingRun\CreateBillingRun;
use App\Application\BillingRun\IllegalStatusTransitionException;
use App\Application\BillingRun\PortalStatusResolver;
use App\Application\Payment\CancelCheckout;
use App\Application\Wizard\WizardProgress;
use App\Application\Wizard\WizardStep;
use App\Enums\BillingMode;
use App\Enums\BillingRunStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\BillingRunRequest;
use App\Models\BillingRun;
use App\Models\Property;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Abrechnungslaeufe des Mandanten.
 *
 * Vorgabe des Masterprompts, Schritt 1 und Abschnitt 5: Abrechnungszeitraum mit
 * Standard 01.01. bis 31.12. des Vorjahres, unterjaehrig zulaessig, Auswahl
 * Schnellabrechnung oder vollstaendige Objektabrechnung mit automatischer
 * Empfehlung.
 *
 * Die Nutzerbestaetigung vor der Finalisierung (Abschnitt 2.3) erfolgt
 * ausschliesslich in Schritt 10 ueber PreviewController::confirm, weil nur dort
 * die Gueltigkeit der Vorschau geprueft und der Nachweis mit Textfassung
 * protokolliert wird. Die Detailseite bietet keinen zweiten Bestaetigungsweg,
 * sie fuehrt den Nutzer zum jeweils naechsten Schritt des Ablaufs.
 */
class BillingRunController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly CreateBillingRun $createBillingRun,
        private readonly BillingRunStateMachine $stateMachine,
        private readonly PortalStatusResolver $status,
        private readonly AuditRecorder $audit,
        private readonly CancelCheckout $cancelCheckout,
        private readonly WizardProgress $wizard,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', BillingRun::class);

        /** @var list<BillingRun> $laeufe */
        $laeufe = $this->context->billingRuns()
            ->with('property')
            ->orderByDesc('billing_year')
            ->orderByDesc('created_at')
            ->get()
            ->all();

        $hinweise = [];

        foreach ($laeufe as $lauf) {
            $schluessel = $lauf->getKey();

            if (is_string($schluessel)) {
                $hinweise[$schluessel] = $this->status->forBillingRun($lauf);
            }
        }

        return view('portal.abrechnungen.index', [
            'laeufe' => $laeufe,
            'hinweise' => $hinweise,
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', BillingRun::class);

        /** @var list<Property> $objekte */
        $objekte = $this->context->properties()
            ->withCount('units')
            ->orderBy('label')
            ->get()
            ->all();

        $vorauswahl = (string) $request->string('property');
        $objekt = null;

        foreach ($objekte as $eintrag) {
            if ($eintrag->getKey() === $vorauswahl) {
                $objekt = $eintrag;
            }
        }

        if ($objekt === null && count($objekte) === 1) {
            $objekt = $objekte[0];
        }

        return view('portal.abrechnungen.formular', [
            'objekte' => $objekte,
            'objekt' => $objekt,
            'zeitraum' => CreateBillingRun::defaultPeriod(),
            'empfehlung' => $objekt instanceof Property
                ? CreateBillingRun::suggestMode($objekt)
                : BillingMode::FULL_PROPERTY,
            'gewerbehinweis' => $objekt instanceof Property
                ? CreateBillingRun::commercialHint($objekt)
                : null,
        ]);
    }

    public function store(BillingRunRequest $request): RedirectResponse
    {
        $this->authorize('create', BillingRun::class);

        /** @var Property $objekt */
        $objekt = $this->context->properties()->findOrFail((string) $request->string('property_id'));
        $this->authorize('update', $objekt);

        $modus = BillingMode::from((string) $request->string('mode'));

        $lauf = $this->createBillingRun->handle(
            property: $objekt,
            actor: $this->context->user(),
            periodStart: (string) $request->string('period_start'),
            periodEnd: (string) $request->string('period_end'),
            mode: $modus,
        );

        $meldung = 'Der Abrechnungslauf ist angelegt. Sie können jederzeit unterbrechen und später fortsetzen.';
        $gewerbe = CreateBillingRun::commercialHint($objekt);

        if ($gewerbe !== null) {
            $meldung .= ' '.$gewerbe;
        }

        return redirect()
            ->route('portal.abrechnungen.show', ['billingRun' => $lauf->getKey()])
            ->with('status', $meldung);
    }

    public function show(string $billingRun): View
    {
        $lauf = $this->lauf($billingRun);
        $this->authorize('view', $lauf);

        /** @var Property $objekt */
        $objekt = $lauf->property()->firstOrFail();

        $aktuell = $lauf->getAttribute('status');
        $aktuell = $aktuell instanceof BillingRunStatus ? $aktuell : BillingRunStatus::DRAFT;

        $naechsterSchritt = $this->naechsterSchritt($lauf, $aktuell);

        return view('portal.abrechnungen.detail', [
            'lauf' => $lauf,
            'objekt' => $objekt,
            'hinweis' => $this->status->forBillingRun($lauf),
            'objektHinweis' => $this->status->forProperty($objekt),
            'gewerbehinweis' => CreateBillingRun::commercialHint($objekt),
            'naechsteSchritte' => BillingRunStateMachine::allowedTargets($aktuell),
            'naechsterSchritt' => $naechsterSchritt,
            // Die Schrittanzeige markiert denselben Schritt als aktuell, den die
            // Karte "Naechster Schritt" nennt (eine Fortschrittsaussage je Seite).
            'fortschritt' => $this->wizard->bar($lauf, $naechsterSchritt['schritt'] ?? null),
            'abbrechbar' => $this->stateMachine->canTransition($lauf, BillingRunStatus::CANCELLED),
        ]);
    }

    /**
     * Der naechste Klick im gefuehrten Ablauf. Vor der Zahlung ist es der
     * Schritt aus WizardProgress, danach Zahlung, Warteseite oder
     * Downloadbereich. Fuer abgebrochene und fehlgeschlagene Laeufe gibt es
     * keinen naechsten Schritt.
     *
     * @return array{titel: string, hinweis: string, url: string, schaltflaeche: string, schritt: WizardStep}|null
     */
    private function naechsterSchritt(BillingRun $lauf, BillingRunStatus $status): ?array
    {
        $parameter = ['billingRun' => $lauf->getKey()];

        switch ($status) {
            case BillingRunStatus::FINALIZED:
                return [
                    'titel' => 'Downloadbereich',
                    'hinweis' => 'Die Abrechnungen sind erstellt. Laden Sie die Dokumente und die Rechnung herunter.',
                    'url' => route('portal.abschluss.show', $parameter),
                    'schaltflaeche' => 'Zum Downloadbereich',
                    'schritt' => WizardStep::ABSCHLUSS,
                ];
            case BillingRunStatus::PAID:
            case BillingRunStatus::FINALIZING:
                return [
                    'titel' => 'Abrechnungen werden erstellt',
                    'hinweis' => 'Die Zahlung ist bestätigt. Die Abrechnungen werden erstellt, Sie erhalten eine E-Mail.',
                    'url' => route('portal.checkout.erfolg', $parameter),
                    'schaltflaeche' => 'Stand ansehen',
                    'schritt' => WizardStep::ABSCHLUSS,
                ];
            case BillingRunStatus::CHECKOUT_PENDING:
                return [
                    'titel' => 'Zahlung',
                    'hinweis' => 'Die Zahlung ist eingeleitet und noch nicht bestätigt.',
                    'url' => route('portal.checkout.show', $parameter),
                    'schaltflaeche' => 'Zur Zahlung',
                    'schritt' => WizardStep::ZAHLUNG,
                ];
            case BillingRunStatus::CANCELLED:
            case BillingRunStatus::FAILED:
                return null;
            default:
                break;
        }

        if ($status === BillingRunStatus::PREVIEW_READY && $this->wizard->checkoutReady($lauf)) {
            return [
                'titel' => 'Zahlung',
                'hinweis' => 'Ihre Bestätigung liegt vor. Der verbindliche Preis wird vor der Zahlung erneut berechnet.',
                'url' => route('portal.checkout.show', $parameter),
                'schaltflaeche' => 'Zur Zahlung',
                'schritt' => WizardStep::ZAHLUNG,
            ];
        }

        $schritt = $this->wizard->resumeStep($lauf);

        return [
            'titel' => sprintf('Schritt %d: %s', $schritt->value, $schritt->label()),
            'hinweis' => $schritt->hint(),
            'url' => route($schritt->routeName(), $parameter),
            'schaltflaeche' => 'Weiter mit: '.$schritt->label(),
            'schritt' => $schritt,
        ];
    }

    /**
     * Abbruch eines Laufs ueber die Statusmaschine.
     *
     * Ein offener Zahlungsvorgang wird zuvor beendet (Zahlungsseite beim
     * Anbieter ablaufen lassen, Zahlung ABGEBROCHEN), damit ein abgebrochener
     * Lauf nicht anschliessend noch bezahlt werden kann.
     */
    public function cancel(string $billingRun): RedirectResponse
    {
        $lauf = $this->lauf($billingRun);
        $this->authorize('delete', $lauf);

        ($this->cancelCheckout)($lauf, $this->context->user());
        $lauf->refresh();

        try {
            $this->stateMachine->transitionTo(
                billingRun: $lauf,
                to: BillingRunStatus::CANCELLED,
                actor: $this->context->user(),
                reason: 'Abbruch durch den Nutzer',
            );
        } catch (IllegalStatusTransitionException $ausnahme) {
            return back()->withErrors(['status' => $ausnahme->getMessage()]);
        }

        return redirect()
            ->route('portal.abrechnungen.index')
            ->with('status', 'Der Abrechnungslauf ist abgebrochen.');
    }

    public function destroy(string $billingRun): RedirectResponse
    {
        $lauf = $this->lauf($billingRun);
        $this->authorize('delete', $lauf);

        // Offene Zahlungsvorgaenge werden vor dem Entfernen beendet, sonst
        // koennte die Zahlungsseite beim Anbieter noch bezahlt werden.
        ($this->cancelCheckout)($lauf, $this->context->user());

        $lauf->delete();

        $this->audit->record(
            action: 'billing_run.deleted',
            subject: $lauf,
            actor: $this->context->user(),
            organization: $this->context->organizationId(),
        );

        return redirect()
            ->route('portal.abrechnungen.index')
            ->with('status', 'Der Abrechnungslauf ist entfernt.');
    }

    private function lauf(string $id): BillingRun
    {
        /** @var BillingRun $lauf */
        $lauf = $this->context->billingRuns()->findOrFail($id);

        return $lauf;
    }
}
