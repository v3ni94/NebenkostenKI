<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Application\Admin\SupportAccessGuard;
use App\Http\Controllers\Controller;
use App\Models\BillingRun;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Organisationen, Objekte und Abrechnungslaeufe (Masterprompt 20).
 *
 * VERBINDLICH: Der Einblick in Kundendaten ist ausschliesslich zu
 * Supportzwecken zulaessig. Er verlangt eine vorher erfasste Begruendung und
 * erzeugt einen Audit-Eintrag mit Akteur, Aktion, Entitaet, Zeitpunkt und
 * gekuerzter IP. Ohne Freischaltung leitet jede Detailseite auf das
 * Begruendungsformular.
 *
 * Die Liste selbst zeigt nur Bezeichnung, Typ und Zaehlwerte. Sie enthaelt
 * keine Abrechnungsinhalte.
 */
final class OrganizationController extends Controller
{
    public function __construct(private readonly SupportAccessGuard $guard) {}

    public function index(Request $request): View
    {
        $suche = trim((string) $request->string('suche'));

        $query = Organization::query()
            ->withCount(['properties', 'billingRuns'])
            ->orderBy('name');

        if ($suche !== '') {
            $query->where('name', 'like', '%'.$suche.'%');
        }

        /** @var list<Organization> $organisationen */
        $organisationen = $query->limit(100)->get()->all();

        return view('admin.organisationen', [
            'organisationen' => $organisationen,
            'suche' => $suche,
        ]);
    }

    public function show(Request $request, Organization $organization): View|RedirectResponse
    {
        $id = (string) $organization->getKey();

        if (! $this->guard->allows('organisation', $id)) {
            return $this->requireReason('organisation', $id);
        }

        $this->guard->recordView($this->actor($request), $organization);

        /** @var list<Property> $objekte */
        $objekte = Property::query()
            ->where('organization_id', $id)
            ->orderBy('created_at')
            ->limit(100)
            ->get()
            ->all();

        /** @var list<BillingRun> $laeufe */
        $laeufe = BillingRun::query()
            ->where('organization_id', $id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->all();

        return view('admin.organisation-detail', [
            'organisation' => $organization,
            'objekte' => $objekte,
            'laeufe' => $laeufe,
        ]);
    }

    public function showProperty(Request $request, Property $property): View|RedirectResponse
    {
        $id = (string) $property->getKey();

        if (! $this->guard->allows('objekt', $id)) {
            return $this->requireReason('objekt', $id);
        }

        $this->guard->recordView($this->actor($request), $property);

        /** @var list<BillingRun> $laeufe */
        $laeufe = BillingRun::query()
            ->where('property_id', $id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->all();

        return view('admin.objekt-detail', [
            'objekt' => $property,
            'laeufe' => $laeufe,
        ]);
    }

    public function showBillingRun(Request $request, BillingRun $billingRun): View|RedirectResponse
    {
        $id = (string) $billingRun->getKey();

        if (! $this->guard->allows('abrechnung', $id)) {
            return $this->requireReason('abrechnung', $id);
        }

        $this->guard->recordView($this->actor($request), $billingRun);

        return view('admin.abrechnung-detail', [
            'lauf' => $billingRun,
        ]);
    }

    private function requireReason(string $entitaet, string $id): RedirectResponse
    {
        return redirect()
            ->route('admin.support.begruendung', ['entitaet' => $entitaet, 'id' => $id])
            ->with(
                'hinweis',
                'Der Einblick in Kundendaten ist nur zu Supportzwecken zulässig. Bitte geben Sie zuerst '
                .'eine Begründung an. Der Zugriff wird protokolliert.'
            );
    }

    private function actor(Request $request): User
    {
        $akteur = $request->user();

        if (! $akteur instanceof User) {
            abort(403);
        }

        return $akteur;
    }
}
