<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Application\Account\OrganizationContext;
use App\Application\BillingRun\PortalStatus;
use App\Application\BillingRun\PortalStatusCategory;
use App\Application\BillingRun\PortalStatusResolver;
use App\Http\Controllers\Controller;
use App\Models\BillingRun;
use App\Models\Property;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;

/**
 * Einstieg in die Anwendung.
 *
 * Vorgabe des Masterprompts, Abschnitt 9: Das Dashboard zeigt statt technischer
 * Fehlermeldungen eine klare Liste in den vier Kategorien Erledigt, Bitte
 * pruefen, Fehlt noch und Blockiert die Abrechnung. Es erscheint kein
 * technischer Providername und kein interner Statuscode.
 *
 * Alle Queries laufen ueber den Mandantenkontext. Zusaetzlich prueft die Policy
 * das Leserecht.
 */
class DashboardController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly PortalStatusResolver $status,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', Property::class);
        $this->authorize('viewAny', BillingRun::class);

        /** @var list<Property> $objekte */
        $objekte = $this->context->properties()
            ->with('units')
            ->orderBy('label')
            ->get()
            ->all();

        /** @var list<BillingRun> $laeufe */
        $laeufe = $this->context->billingRuns()
            ->with('property')
            ->orderByDesc('billing_year')
            ->orderByDesc('created_at')
            ->get()
            ->all();

        $objektStatus = [];

        foreach ($objekte as $objekt) {
            $schluessel = $objekt->getKey();

            if (is_string($schluessel)) {
                $objektStatus[$schluessel] = $this->status->forProperty($objekt);
            }
        }

        $laufStatus = [];

        foreach ($laeufe as $lauf) {
            $schluessel = $lauf->getKey();

            if (is_string($schluessel)) {
                $laufStatus[$schluessel] = $this->status->forBillingRun($lauf);
            }
        }

        return view('portal.dashboard', [
            'objekte' => $objekte,
            'laeufe' => $laeufe,
            'objektStatus' => $objektStatus,
            'laufStatus' => $laufStatus,
            'zaehler' => $this->zaehler(array_merge(
                array_values($objektStatus),
                array_values($laufStatus)
            )),
        ]);
    }

    /**
     * Anzahl der Eintraege je Statuskategorie.
     *
     * @param  list<PortalStatus>  $status
     * @return array<string, int>
     */
    private function zaehler(array $status): array
    {
        $zaehler = [];

        foreach (PortalStatusCategory::all() as $kategorie) {
            $zaehler[$kategorie] = 0;
        }

        foreach ($status as $eintrag) {
            $zaehler[$eintrag->kategorie] = ($zaehler[$eintrag->kategorie] ?? 0) + 1;
        }

        return $zaehler;
    }
}
