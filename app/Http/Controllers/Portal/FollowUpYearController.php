<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Application\FollowUpYear\CarryOverToFollowUpYear;
use App\Application\FollowUpYear\KeinFinalisierterVorjahreslaufException;
use App\Http\Controllers\Controller;
use App\Models\BillingRun;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Start des Folgejahreslaufs (Masterprompt 8.3).
 *
 * Der Aufruf kommt entweder aus dem Portal oder aus dem CTA einer
 * Erinnerungsmail. Der CTA ist zeitlich begrenzt signiert und enthaelt keine
 * Kundendaten. Die Kennung des Objekts ist eine ULID.
 *
 * MANDANTENSCHUTZ, verbindlich (Masterprompt 19):
 *
 *  - Die Signatur ersetzt die Autorisierung NICHT. Der Aufruf setzt eine
 *    Anmeldung voraus, und es wird zusaetzlich geprueft, ob das Objekt zu einer
 *    Organisation des angemeldeten Nutzers gehoert.
 *  - Ein fremdes Objekt fuehrt zu 404 und nicht zu 403. Ein 403 wuerde
 *    bestaetigen, dass die Kennung existiert.
 *  - Zusaetzlich gelten die Policies der regulaeren Anlage eines Laufs
 *    (PropertyPolicy::update, BillingRunPolicy::create). Eine nur lesende
 *    Rolle legt auch ueber den CTA keinen Lauf an.
 *
 * Der Vorgang ist idempotent. Ein zweiter Aufruf oeffnet denselben
 * vorbereiteten Lauf und legt keinen zweiten an.
 */
class FollowUpYearController extends Controller
{
    public function __construct(private readonly CarryOverToFollowUpYear $uebernahme) {}

    public function start(Request $request, string $property, string $jahr): RedirectResponse
    {
        $nutzer = $request->user();

        abort_unless($nutzer instanceof User, 404);

        /** @var Property|null $objekt */
        $objekt = Property::query()->whereKey($property)->first();

        abort_unless($objekt instanceof Property, 404);

        $organisation = $objekt->getAttribute('organization_id');

        abort_unless(
            is_string($organisation) && $nutzer->belongsToOrganization($organisation),
            404
        );

        // Dieselben Policies wie bei der regulaeren Anlage eines Laufs
        // (BillingRunController::store): Schreibrecht am Objekt und Recht zur
        // Anlage. Eine nur lesende Rolle legt keinen Lauf an. Verweigerung als
        // 404, damit die Existenz des Objekts nicht bestaetigt wird.
        abort_unless(
            Gate::forUser($nutzer)->allows('update', $objekt)
                && Gate::forUser($nutzer)->allows('create', BillingRun::class),
            404
        );

        $zieljahr = (int) $jahr;

        abort_unless($zieljahr >= 2000 && $zieljahr <= 2200, 404);

        try {
            $ergebnis = $this->uebernahme->handle($objekt, $nutzer, $zieljahr);
        } catch (KeinFinalisierterVorjahreslaufException $fehler) {
            // Ohne abgeschlossenen Vorjahreslauf gibt es nichts zu uebernehmen.
            // Der Nutzer wird auf die gewoehnliche Anlage geleitet.
            return redirect()
                ->route('portal.abrechnungen.create', ['property' => $objekt->getKey()])
                ->with('status', $fehler->getMessage());
        }

        return redirect()
            ->route('portal.abrechnungen.show', ['billingRun' => $ergebnis->lauf->getKey()])
            ->with('status', $ergebnis->zusammenfassung());
    }
}
