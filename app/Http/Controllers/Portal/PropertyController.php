<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Application\Account\AuditRecorder;
use App\Application\Account\OrganizationContext;
use App\Application\BillingRun\PortalStatusResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\PropertyRequest;
use App\Models\Property;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Objekte des Mandanten.
 *
 * MANDANTENSCHUTZ, verbindlich:
 *
 *  1. Jede Query startet beim Mandantenkontext, niemals bei Property::find().
 *     Ein fremder Datensatz ist damit gar nicht auffindbar und fuehrt zu 404,
 *     ohne dass die Fehlermeldung etwas ueber seine Existenz verraet.
 *  2. Zusaetzlich entscheidet die PropertyPolicy objektbezogen. Beide Ebenen
 *     greifen zusammen, keine ersetzt die andere.
 *
 * Jede Aktion speichert vollstaendig und leitet mit einer Statusmeldung weiter.
 * Es gibt keinen halb gespeicherten Zwischenstand, der Ablauf ist jederzeit
 * unterbrechbar.
 */
class PropertyController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly PortalStatusResolver $status,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', Property::class);

        /** @var list<Property> $objekte */
        $objekte = $this->context->properties()
            ->with('landlord')
            ->withCount('units')
            ->orderBy('label')
            ->get()
            ->all();

        $hinweise = [];

        foreach ($objekte as $objekt) {
            $schluessel = $objekt->getKey();

            if (is_string($schluessel)) {
                $hinweise[$schluessel] = $this->status->forProperty($objekt);
            }
        }

        return view('portal.objekte.index', [
            'objekte' => $objekte,
            'hinweise' => $hinweise,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Property::class);

        return view('portal.objekte.formular', [
            'objekt' => null,
        ]);
    }

    public function store(PropertyRequest $request): RedirectResponse
    {
        $this->authorize('create', Property::class);

        /** @var Property $objekt */
        $objekt = Property::query()->create(array_merge(
            $this->attribute($request),
            [
                'organization_id' => $this->context->organizationId(),
                'created_by_user_id' => $this->context->user()->getKey(),
                'country' => 'DE',
                'is_active' => true,
            ]
        ));

        $this->audit->record(
            action: 'property.created',
            subject: $objekt,
            actor: $this->context->user(),
            organization: $this->context->organizationId(),
        );

        return redirect()
            ->route('portal.einheiten.index', ['property' => $objekt->getKey()])
            ->with('status', 'Das Objekt ist gespeichert. Erfassen Sie nun die Einheiten.');
    }

    public function edit(string $property): View
    {
        $objekt = $this->objekt($property);
        $this->authorize('update', $objekt);

        return view('portal.objekte.formular', [
            'objekt' => $objekt,
        ]);
    }

    public function update(PropertyRequest $request, string $property): RedirectResponse
    {
        $objekt = $this->objekt($property);
        $this->authorize('update', $objekt);

        $objekt->fill($this->attribute($request))->save();

        $this->audit->record(
            action: 'property.updated',
            subject: $objekt,
            actor: $this->context->user(),
            organization: $this->context->organizationId(),
        );

        return redirect()
            ->route('portal.objekte.index')
            ->with('status', 'Die Änderungen am Objekt sind gespeichert.');
    }

    public function destroy(string $property): RedirectResponse
    {
        $objekt = $this->objekt($property);
        $this->authorize('delete', $objekt);

        // Das Modell verwendet SoftDeletes. Der Datensatz bleibt erhalten und
        // ist damit fuer eine spaetere Nachfrage nachvollziehbar. Die
        // endgueltige Loeschung laeuft ueber den Kontoloeschworkflow.
        $objekt->delete();

        $this->audit->record(
            action: 'property.deleted',
            subject: $objekt,
            actor: $this->context->user(),
            organization: $this->context->organizationId(),
        );

        return redirect()
            ->route('portal.objekte.index')
            ->with('status', 'Das Objekt ist entfernt.');
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
     * @return array<string, string|null>
     */
    private function attribute(PropertyRequest $request): array
    {
        /** @var array<string, string|null> $werte */
        $werte = $request->safe()->only([
            'label',
            'address_line',
            'address_extra',
            'postal_code',
            'city',
            'kind',
            'weg_name',
            'total_living_area_sqm',
            'total_heated_area_sqm',
            'mea_denominator',
            'individual_key_1_label',
            'individual_key_2_label',
            'individual_key_3_label',
            'individual_key_4_label',
            'individual_key_5_label',
            'notes',
        ]);

        return $werte;
    }
}
