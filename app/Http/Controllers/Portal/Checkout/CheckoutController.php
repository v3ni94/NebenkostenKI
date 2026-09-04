<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal\Checkout;

use App\Application\Account\OrganizationContext;
use App\Application\Payment\CalculatePrice;
use App\Application\Payment\CancelCheckout;
use App\Application\Payment\CheckoutTexts;
use App\Application\Payment\Exceptions\CheckoutNotAllowedException;
use App\Application\Payment\Exceptions\PriceNotPayableException;
use App\Application\Payment\OperatorInvoiceBlocker;
use App\Application\Payment\StartCheckout;
use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\StartCheckoutRequest;
use App\Models\BillingRun;
use App\Models\User;
use App\Services\Payment\Exceptions\CheckoutProviderException;
use App\Services\Payment\Exceptions\PaymentConfigurationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Schritt 11: Zahlung (Abschnitt 15.1).
 *
 * MANDANTENSCHUTZ, verbindlich: Es wird kein implizites Route-Model-Binding
 * verwendet. Der Lauf wird ausschliesslich ueber die auf den Mandanten gescopte
 * Query geladen; ein fremder Lauf fuehrt zu 404 und verraet damit nicht seine
 * Existenz. Zusaetzlich entscheidet die Policy objektbezogen.
 *
 * PREIS: Der angezeigte Preis wird bei jedem Aufruf serverseitig neu berechnet.
 * Es gibt keinen Weg, einen Betrag aus dem Formular zu uebernehmen. Liegt der
 * konfigurierte Preis ausserhalb des Korridors, zeigt die Seite das als
 * verstaendliche Meldung ohne Zahlungsschaltflaeche.
 *
 * FREISCHALTUNG: Nur der signaturgepruefte Webhook schaltet die Finalisierung
 * frei. Die Rueckleitung des Browsers wird von CheckoutReturnController
 * behandelt und aendert den Zustand des Laufs ausdruecklich nicht.
 *
 * FEHLER DES ANBIETERS: Ist die Zahlungsseite nicht anlegbar oder die
 * Zahlungsanbindung nicht konfiguriert, erhaelt der Nutzer den vorbereiteten
 * Hinweis als Formularfehler und keinen Serverfehler.
 */
class CheckoutController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly CalculatePrice $prices,
        private readonly StartCheckout $startCheckout,
        private readonly CancelCheckout $cancelCheckout,
        private readonly OperatorInvoiceBlocker $blocker,
    ) {}

    /**
     * Uebersicht mit Preis, Zustimmungen und Schaltflaeche zur Zahlung.
     */
    public function show(string $billingRun): View
    {
        $lauf = $this->findBillingRun($billingRun);
        $this->authorize('checkout', $lauf);

        $anzahl = $this->prices->statementCount($lauf);
        $preis = null;
        $preisfehler = null;

        try {
            $preis = $this->prices->estimate($anzahl);
        } catch (PriceNotPayableException $exception) {
            $preisfehler = $exception->getMessage();
        }

        return view('portal.checkout.index', [
            'lauf' => $lauf,
            'objekt' => $lauf->getRelationValue('property'),
            'preis' => $preis,
            'preisfehler' => $preisfehler,
            'anzahl' => $anzahl,
            'bestaetigungFehlt' => $lauf->getAttribute('review_confirmed_at') === null
                || $lauf->getAttribute('responsibility_confirmed_at') === null,
            'anschriftFehlt' => ! StartCheckout::hasBillingAddress($lauf),
            'textSofortigeAusfuehrung' => CheckoutTexts::IMMEDIATE_PERFORMANCE,
            'textVertragsgrundlagen' => CheckoutTexts::TERMS,
            'textfassung' => CheckoutTexts::VERSION,
            'rechnungsblocker' => $this->blocker->state(),
        ]);
    }

    /**
     * Zahlung einleiten und auf die gehostete Zahlungsseite weiterleiten.
     */
    public function store(StartCheckoutRequest $request, string $billingRun): RedirectResponse
    {
        $lauf = $this->findBillingRun($billingRun);
        $this->authorize('checkout', $lauf);

        $nutzer = $request->user();

        abort_unless($nutzer instanceof User, 404);

        try {
            $start = ($this->startCheckout)(
                $lauf,
                $nutzer,
                $request->consent(),
                route('portal.checkout.erfolg', ['billingRun' => $lauf->getKey()]),
                route('portal.checkout.abbruch', ['billingRun' => $lauf->getKey()]),
            );
        } catch (CheckoutNotAllowedException|PriceNotPayableException|CheckoutProviderException $exception) {
            return $this->backWithError($lauf, $exception->getMessage());
        } catch (PaymentConfigurationException $exception) {
            // Der Name der fehlenden Variable ist eine Betriebsangabe und gehoert
            // in das Log, nicht auf die Kundenseite.
            Log::error('Die Zahlungsanbindung ist nicht vollständig konfiguriert.', [
                'fehler' => $exception->getMessage(),
            ]);

            return $this->backWithError(
                $lauf,
                'Die Zahlung ist derzeit nicht möglich, weil die Zahlungsanbindung nicht vollständig eingerichtet '
                .'ist. Bitte versuchen Sie es später erneut oder wenden Sie sich an den Support.',
            );
        }

        return redirect()->away($start->redirectUrl);
    }

    /**
     * Abbruch durch den Nutzer. Der Lauf bleibt im Vorschauzustand.
     */
    public function destroy(string $billingRun): RedirectResponse
    {
        $lauf = $this->findBillingRun($billingRun);
        $this->authorize('checkout', $lauf);

        ($this->cancelCheckout)($lauf, $this->context->user());

        return redirect()
            ->route('portal.checkout.show', ['billingRun' => $lauf->getKey()])
            ->with('status', 'Der Zahlungsvorgang wurde abgebrochen. Ihre Vorschau bleibt unverändert erhalten.');
    }

    private function backWithError(BillingRun $lauf, string $meldung): RedirectResponse
    {
        return redirect()
            ->route('portal.checkout.show', ['billingRun' => $lauf->getKey()])
            ->withErrors(['zahlung' => $meldung]);
    }

    private function findBillingRun(string $id): BillingRun
    {
        /** @var BillingRun $lauf */
        $lauf = $this->context->billingRuns()->with('property')->findOrFail($id);

        return $lauf;
    }
}
