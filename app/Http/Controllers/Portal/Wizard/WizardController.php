<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal\Wizard;

use App\Application\Wizard\WizardProgress;
use App\Application\Wizard\WizardStep;
use App\Http\Controllers\Controller;
use App\Models\BillingRun;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Gemeinsame Grundlage der Schritte 7 bis 10.
 *
 * MANDANTENSCHUTZ: Jede Aktion prüft die Policy des Abrechnungslaufs. Ein
 * fremder Datensatz führt zu 403 beziehungsweise 404, ohne dass die Meldung
 * seine Existenz verrät.
 */
abstract class WizardController extends Controller
{
    public function __construct(protected readonly WizardProgress $progress) {}

    /**
     * Gemeinsame Daten der Ansicht: Fortschrittsleiste und Wiedereinstieg.
     *
     * @return array<string, mixed>
     */
    protected function frame(BillingRun $billingRun, WizardStep $step): array
    {
        return [
            'billingRun' => $billingRun,
            'schritt' => $step,
            'fortschritt' => $this->progress->bar($billingRun, $step),
            'wiedereinstieg' => $this->progress->resumeHint($billingRun, $step),
        ];
    }

    protected function user(): User
    {
        $user = request()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    protected function back(BillingRun $billingRun, WizardStep $step, string $meldung): RedirectResponse
    {
        return redirect()
            ->route($step->routeName(), ['billingRun' => $billingRun->getKey()])
            ->with('status', $meldung);
    }

    protected function withError(BillingRun $billingRun, WizardStep $step, string $feld, string $meldung): RedirectResponse
    {
        return redirect()
            ->route($step->routeName(), ['billingRun' => $billingRun->getKey()])
            ->withErrors([$feld => $meldung]);
    }
}
