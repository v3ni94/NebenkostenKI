<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Application\Account\AuditRecorder;
use App\Application\Account\OrganizationContext;
use App\Application\Wizard\PreviewInvalidator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\LandlordRequest;
use App\Models\Landlord;
use App\Models\Property;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Vermieter eines Objekts (Schritt 4, Masterprompt 2.2).
 *
 * Absender und inhaltlich Verantwortlicher der Mieterabrechnung ist der
 * Vermieter beziehungsweise Eigentuemer. Er wird je Objekt gepflegt und ueber
 * landlord_id am Objekt gefuehrt. Ohne Vermieter meldet der Pruefbericht einen
 * Blocker (Regel VERMIETER_FEHLT).
 *
 * MANDANTENSCHUTZ wie bei den Objekten: Das Objekt wird ausschliesslich ueber
 * den Mandantenkontext geladen, zusaetzlich entscheiden PropertyPolicy und
 * LandlordPolicy objektbezogen.
 *
 * IBAN und BIC werden im Modell verschluesselt gespeichert und niemals
 * protokolliert. Der Revisionseintrag enthaelt keine Bankdaten.
 */
class LandlordController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly AuditRecorder $audit,
        private readonly PreviewInvalidator $invalidator,
    ) {}

    public function edit(string $property): View
    {
        $objekt = $this->objekt($property);
        $this->authorize('update', $objekt);

        $vermieter = $this->vermieter($objekt);

        if ($vermieter instanceof Landlord) {
            $this->authorize('view', $vermieter);
        }

        return view('portal.vermieter.formular', [
            'objekt' => $objekt,
            'vermieter' => $vermieter,
        ]);
    }

    public function update(LandlordRequest $request, string $property): RedirectResponse
    {
        $objekt = $this->objekt($property);
        $this->authorize('update', $objekt);

        $vermieter = $this->vermieter($objekt);
        $werte = $this->attribute($request);

        if ($vermieter instanceof Landlord) {
            $this->authorize('update', $vermieter);

            $vermieter->fill($werte)->save();
            $aktion = 'landlord.updated';
        } else {
            $this->authorize('create', Landlord::class);

            $vermieter = DB::transaction(function () use ($objekt, $werte): Landlord {
                /** @var Landlord $neu */
                $neu = Landlord::query()->create(array_merge($werte, [
                    'organization_id' => $this->context->organizationId(),
                    'country' => 'DE',
                ]));

                $objekt->forceFill(['landlord_id' => $neu->getKey()])->save();

                return $neu;
            });
            $aktion = 'landlord.created';
        }

        $this->audit->record(
            action: $aktion,
            subject: $vermieter,
            actor: $this->context->user(),
            organization: $this->context->organizationId(),
            metadata: ['property_id' => (string) $objekt->getKey()],
        );

        // Der Vermieter ist Absender jeder Mieterabrechnung. Vorschau und
        // Bestaetigung offener Laeufe des Objekts verlieren ihre Grundlage.
        $this->invalidator->forProperty($objekt, $this->context->user());

        return redirect()
            ->route('portal.objekte.index')
            ->with('status', 'Die Vermieterdaten sind gespeichert.');
    }

    /**
     * Objekt aus dem Mandantenbestand oder 404.
     */
    private function objekt(string $id): Property
    {
        /** @var Property $objekt */
        $objekt = $this->context->properties()->findOrFail($id);

        return $objekt;
    }

    /**
     * Vermieter des Objekts, mandantensicher geladen.
     */
    private function vermieter(Property $objekt): ?Landlord
    {
        $id = $objekt->getAttribute('landlord_id');

        if (! is_string($id) || $id === '') {
            return null;
        }

        $vermieter = $this->context->landlords()->whereKey($id)->first();

        return $vermieter instanceof Landlord ? $vermieter : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function attribute(LandlordRequest $request): array
    {
        /** @var array<string, mixed> $werte */
        $werte = $request->safe()->only([
            'sender_name',
            'company_name',
            'address_line',
            'address_extra',
            'postal_code',
            'city',
            'email',
            'phone',
            'iban',
            'bic',
            'account_holder',
        ]);

        $werte['show_bank_details_on_statement'] = $request->boolean('show_bank_details_on_statement');

        return $werte;
    }
}
