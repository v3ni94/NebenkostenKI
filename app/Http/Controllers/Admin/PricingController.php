<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Admin\AdminAuditRecorder;
use App\Application\Admin\PricingSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PriceCheckRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Preis je Mieterabrechnung und Umsatzsteuersatz (Masterprompt 1.3, 20).
 *
 * VERBINDLICH: Eine Adminaenderung an Preis, Regel oder Prompt wirkt
 * ausschliesslich auf NEUE Berechnungsstaende. Bestehende Calculation
 * Snapshots bleiben unveraendert und reproduzierbar. Dieser Controller
 * schreibt deshalb nichts in einen Berechnungsstand.
 *
 * Zur Persistenz siehe App\Application\Admin\PricingSettings: es gibt keine
 * Preistabelle, der Preis kommt aus der Serverumgebung. Der Adminbereich
 * prueft eine geplante Aenderung gegen den Korridor und protokolliert die
 * Pruefung.
 */
final class PricingController extends Controller
{
    public function __construct(
        private readonly PricingSettings $pricing,
        private readonly AdminAuditRecorder $audit,
    ) {}

    public function index(): View
    {
        return view('admin.preise', [
            'zustand' => $this->pricing->state(),
            'geschuetzte_staende' => $this->pricing->protectedSnapshotCount(),
        ]);
    }

    public function check(PriceCheckRequest $request): RedirectResponse
    {
        $nutzer = $request->user();

        if (! $nutzer instanceof User) {
            abort(403);
        }

        $preis = $request->preisCent();

        $this->audit->record(
            action: 'admin.pricing.checked',
            actor: $nutzer,
            metadata: [
                'geplanter_preis_cent' => $preis,
                'im_korridor' => $this->pricing->isWithinRange($preis),
            ],
        );

        return redirect()
            ->route('admin.preise')
            ->with('status', $this->pricing->effectNote($preis));
    }
}
