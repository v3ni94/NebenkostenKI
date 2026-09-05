<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Account\OrganizationContext;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Setzt und prueft den aktiven Mandanten der Anfrage.
 *
 * Vorgabe des Masterprompts, Abschnitt 19: Jede Query wird nach Organisation
 * gescopet, ohne Mitgliedschaft besteht kein Zugriff.
 *
 * Ablauf:
 *
 *  1. Ohne angemeldeten Nutzer greift die Middleware nicht, das erledigt auth.
 *  2. Die gewuenschte Organisation kommt aus der Session. Sie wird gegen die
 *     Mitgliedschaften des Nutzers geprueft. Ein manipulierter Sessionwert
 *     fuehrt nicht zu Zugriff, sondern zum Verwerfen des Werts.
 *  3. Ohne gueltige Auswahl wird die erste Mitgliedschaft des Nutzers gesetzt.
 *     Nach der Registrierung besitzt jeder Nutzer genau eine Organisation.
 *  4. Besitzt der Nutzer keine einzige Mitgliedschaft, wird der Zugriff mit
 *     403 verweigert. Das ist ein Datenfehler und kein Normalfall, weil die
 *     Registrierung die Organisation in derselben Transaktion anlegt.
 *
 * Der Sessionschluessel wird bewusst nicht aus einem Requestparameter gesetzt.
 * Ein Mandantenwechsel erhaelt spaeter eine eigene, ausdrueckliche Route.
 */
class EnsureOrganizationContext
{
    public const SESSION_KEY = 'aktive_organisation_id';

    public function __construct(private readonly OrganizationContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $mitgliedschaften = $user->organizationIds();

        if ($mitgliedschaften === []) {
            abort(403, 'Ihrem Konto ist kein Bereich zugeordnet. Bitte wenden Sie sich an den Support.');
        }

        $gewaehlt = $request->session()->get(self::SESSION_KEY);

        if (! is_string($gewaehlt) || ! in_array($gewaehlt, $mitgliedschaften, true)) {
            $gewaehlt = $mitgliedschaften[0];
            $request->session()->put(self::SESSION_KEY, $gewaehlt);
        }

        $organization = Organization::query()->find($gewaehlt);

        if (! $organization instanceof Organization) {
            $request->session()->forget(self::SESSION_KEY);

            abort(403, 'Ihrem Konto ist kein Bereich zugeordnet. Bitte wenden Sie sich an den Support.');
        }

        // Zweite Verteidigungslinie: die Mitgliedschaft wird objektbezogen
        // geprueft, nicht nur anhand der Liste der Identifikatoren.
        if (! $user->belongsToOrganization($organization)) {
            abort(403, 'Für diesen Bereich besteht keine Berechtigung.');
        }

        $this->context->set($organization, $user);

        return $next($request);
    }
}
