<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Application\Account\AuditRecorder;
use App\Application\Account\OrganizationContext;
use App\Application\BillingRun\PortalStatusResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\UnitRequest;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Einheiten eines Objekts.
 *
 * Erfasst werden Flaeche, beheizte Flaeche, Miteigentumsanteil und die
 * individuellen Schluesselwerte 1 bis 5. Die Plausibilitaet der Flaechen- und
 * Anteilssummen wird als Hinweis ausgegeben und blockiert die Erfassung nicht.
 * Gemeinschaftsflaechen und Teileigentum fuehren regelmaessig zu Abweichungen,
 * die fachlich richtig sind.
 *
 * Der Mandantenschutz laeuft wie bei den Objekten ueber den Kontext und
 * zusaetzlich ueber die UnitPolicy.
 */
class UnitController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly PortalStatusResolver $status,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(string $property): View
    {
        $objekt = $this->objekt($property);
        $this->authorize('view', $objekt);
        $this->authorize('viewAny', Unit::class);

        /** @var list<Unit> $einheiten */
        $einheiten = $objekt->units()->orderBy('label')->get()->all();

        return view('portal.einheiten.index', [
            'objekt' => $objekt,
            'einheiten' => $einheiten,
            'plausibilitaet' => $this->status->plausibilityHints($objekt),
        ]);
    }

    public function create(string $property): View
    {
        $objekt = $this->objekt($property);
        $this->authorize('update', $objekt);
        $this->authorize('create', Unit::class);

        return view('portal.einheiten.formular', [
            'objekt' => $objekt,
            'einheit' => null,
        ]);
    }

    public function store(UnitRequest $request, string $property): RedirectResponse
    {
        $objekt = $this->objekt($property);
        $this->authorize('update', $objekt);
        $this->authorize('create', Unit::class);

        /** @var Unit $einheit */
        $einheit = Unit::query()->create(array_merge(
            $this->attribute($request),
            [
                'organization_id' => $this->context->organizationId(),
                'property_id' => $objekt->getKey(),
            ]
        ));

        $this->audit->record(
            action: 'unit.created',
            subject: $einheit,
            actor: $this->context->user(),
            organization: $this->context->organizationId(),
        );

        return redirect()
            ->route('portal.einheiten.index', ['property' => $objekt->getKey()])
            ->with('status', 'Die Einheit ist gespeichert.');
    }

    public function edit(string $unit): View
    {
        $einheit = $this->einheit($unit);
        $this->authorize('update', $einheit);

        /** @var Property $objekt */
        $objekt = $einheit->property()->firstOrFail();

        return view('portal.einheiten.formular', [
            'objekt' => $objekt,
            'einheit' => $einheit,
        ]);
    }

    public function update(UnitRequest $request, string $unit): RedirectResponse
    {
        $einheit = $this->einheit($unit);
        $this->authorize('update', $einheit);

        $einheit->fill($this->attribute($request))->save();

        $this->audit->record(
            action: 'unit.updated',
            subject: $einheit,
            actor: $this->context->user(),
            organization: $this->context->organizationId(),
        );

        return redirect()
            ->route('portal.einheiten.index', ['property' => $einheit->getAttribute('property_id')])
            ->with('status', 'Die Änderungen an der Einheit sind gespeichert.');
    }

    public function destroy(string $unit): RedirectResponse
    {
        $einheit = $this->einheit($unit);
        $this->authorize('delete', $einheit);

        $objektId = $einheit->getAttribute('property_id');

        $einheit->delete();

        $this->audit->record(
            action: 'unit.deleted',
            subject: $einheit,
            actor: $this->context->user(),
            organization: $this->context->organizationId(),
        );

        return redirect()
            ->route('portal.einheiten.index', ['property' => $objektId])
            ->with('status', 'Die Einheit ist entfernt.');
    }

    private function objekt(string $id): Property
    {
        /** @var Property $objekt */
        $objekt = $this->context->properties()->findOrFail($id);

        return $objekt;
    }

    private function einheit(string $id): Unit
    {
        /** @var Unit $einheit */
        $einheit = $this->context->units()->findOrFail($id);

        return $einheit;
    }

    /**
     * @return array<string, mixed>
     */
    private function attribute(UnitRequest $request): array
    {
        /** @var array<string, mixed> $werte */
        $werte = $request->safe()->only([
            'label',
            'location',
            'unit_number',
            'living_area_sqm',
            'heated_area_sqm',
            'mea',
            'room_count',
            'individual_key_1_value',
            'individual_key_2_value',
            'individual_key_3_value',
            'individual_key_4_value',
            'individual_key_5_value',
            'notes',
        ]);

        $werte['is_commercial'] = $request->boolean('is_commercial');
        $werte['is_owner_occupied'] = $request->boolean('is_owner_occupied');

        return $werte;
    }
}
