<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal\Checkout;

use App\Application\Account\OrganizationContext;
use App\Application\Payment\CancelCheckout;
use App\Application\Wizard\WizardProgress;
use App\Application\Wizard\WizardStep;
use App\Enums\BillingRunStatus;
use App\Http\Controllers\Controller;
use App\Models\BillingRun;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Rueckleitung aus der gehosteten Zahlungsseite.
 *
 * VERBINDLICH (Abschnitt 15.1, ADR-010): Der Browser-Redirect ist NIEMALS
 * Zahlungsnachweis. Diese Klasse aendert deshalb weder den Status des
 * Abrechnungslaufs noch den Status der Zahlung und loest keine Finalisierung
 * aus. Sie zeigt ausschliesslich den aktuellen Stand an. Freigeschaltet wird
 * ausschliesslich durch die signaturgeprüfte Rueckmeldung des Anbieters.
 *
 * Die Erfolgsseite ist bewusst als Wartehinweis gestaltet: Solange die
 * Rueckmeldung nicht eingegangen ist, sagt sie ausdruecklich nicht, dass die
 * Zahlung bestaetigt sei.
 */
class CheckoutReturnController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly CancelCheckout $cancelCheckout,
        private readonly WizardProgress $progress,
    ) {}

    /**
     * Rueckleitung nach abgeschlossener Eingabe beim Anbieter.
     */
    public function success(string $billingRun): View|RedirectResponse
    {
        $lauf = $this->findBillingRun($billingRun);
        $this->authorize('view', $lauf);

        $status = $lauf->getAttribute('status');

        if ($status === BillingRunStatus::FINALIZED) {
            return redirect()->route('portal.abschluss.show', ['billingRun' => $lauf->getKey()]);
        }

        $bezahlt = $lauf->getAttribute('paid_at') !== null
            || ($status instanceof BillingRunStatus && $status->isPaid());

        return view('portal.checkout.warten', [
            'lauf' => $lauf,
            'schritt' => WizardStep::ZAHLUNG,
            'fortschritt' => $this->progress->bar($lauf, WizardStep::ZAHLUNG),
            'objekt' => $lauf->getRelationValue('property'),
            'bezahlt' => $bezahlt,
            // Zahlung bestaetigt, Erstellung gescheitert: der Betrieb holt sie
            // nach. Dem Kunden wird das ehrlich gesagt.
            'verzoegert' => $bezahlt && $status === BillingRunStatus::FAILED,
        ]);
    }

    /**
     * Rueckleitung nach Abbruch beim Anbieter. Der Lauf bleibt in der Vorschau.
     */
    public function cancel(string $billingRun): RedirectResponse
    {
        $lauf = $this->findBillingRun($billingRun);
        $this->authorize('view', $lauf);

        ($this->cancelCheckout)($lauf, $this->context->user());

        return redirect()
            ->route('portal.checkout.show', ['billingRun' => $lauf->getKey()])
            ->with('status', 'Der Zahlungsvorgang wurde abgebrochen. Es wurde nichts abgebucht, '
                .'und Ihre Vorschau bleibt unverändert erhalten.');
    }

    private function findBillingRun(string $id): BillingRun
    {
        /** @var BillingRun $lauf */
        $lauf = $this->context->billingRuns()->with('property')->findOrFail($id);

        return $lauf;
    }
}
