<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Application\Account\AuditRecorder;
use App\Application\Account\OrganizationContext;
use App\Application\BillingRun\PortalStatusResolver;
use App\Application\Wizard\PreviewInvalidator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\UnitRequest;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
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
        private readonly PreviewInvalidator $invalidator,
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

        $werte = $this->attribute($request);

        // Eine weich geloeschte Einheit belegt ihre Bezeichnung im Unique-Index
        // weiter. Sie wird ausdruecklich NICHT wiederhergestellt: Ihre alten
        // Mietverhaeltnisse, Personen, Belegungen und Vorauszahlungen gelangen
        // sonst still zurueck in die Abrechnung. Stattdessen wird die
        // Bezeichnung freigegeben und eine neue Einheit angelegt.
        $this->gebeBezeichnungFrei($objekt, is_string($werte['label'] ?? null) ? $werte['label'] : '');

        /** @var Unit $einheit */
        $einheit = Unit::query()->create(array_merge(
            $werte,
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

        // Flaechen und Anteile sind Schluesselwerte: Vorschau und Bestaetigung
        // offener Laeufe verlieren ihre Grundlage.
        $this->invalidator->forUnit($einheit, $this->context->user());

        return redirect()
            ->route('portal.einheiten.index', ['property' => $einheit->getAttribute('property_id')])
            ->with('status', 'Die Änderungen an der Einheit sind gespeichert.');
    }

    public function destroy(string $unit): RedirectResponse
    {
        $einheit = $this->einheit($unit);
        $this->authorize('delete', $einheit);

        $objektId = $einheit->getAttribute('property_id');

        // Die Mietverhaeltnisse der Einheit werden mit weich geloescht
        // (Loeschkaskade im Modell), damit sie nicht als verwaiste Zeilen in
        // der Abrechnung verbleiben.
        $einheit->delete();

        $this->audit->record(
            action: 'unit.deleted',
            subject: $einheit,
            actor: $this->context->user(),
            organization: $this->context->organizationId(),
        );

        $this->invalidator->forUnit($einheit, $this->context->user());

        return redirect()
            ->route('portal.einheiten.index', ['property' => $objektId])
            ->with('status', 'Die Einheit ist entfernt.');
    }

    /**
     * Gibt die Bezeichnung einer weich geloeschten Einheit frei.
     *
     * Bestehen zu der entfernten Einheit keine Abrechnungsbezuege (keine
     * Mietverhaeltnisse, keine Mieterabrechnungen, keine Zaehler, keine
     * Leerstaende), wird die Zeile endgueltig entfernt. Sonst bleibt sie fuer
     * die Nachvollziehbarkeit erhalten und erhaelt eine Bezeichnung mit
     * Zusatz, damit der Unique-Index die neue Einheit zulaesst.
     */
    private function gebeBezeichnungFrei(Property $objekt, string $label): void
    {
        if ($label === '') {
            return;
        }

        DB::transaction(function () use ($objekt, $label): void {
            /** @var list<Unit> $entfernte */
            $entfernte = $objekt->units()
                ->onlyTrashed()
                ->where('label', $label)
                ->get()
                ->all();

            foreach ($entfernte as $entfernt) {
                $bezuege = $entfernt->tenancies()->withTrashed()->exists()
                    || $entfernt->unitStatements()->exists()
                    || $entfernt->meterDevices()->withTrashed()->exists()
                    || $entfernt->vacancyPeriods()->exists();

                if (! $bezuege) {
                    $entfernt->forceDelete();

                    continue;
                }

                // Die Spalte fasst 120 Zeichen; der Zusatz hat Vorrang.
                $zusatz = sprintf(' (entfernt %s)', (string) $entfernt->getKey());

                $entfernt->forceFill([
                    'label' => mb_substr($label, 0, max(1, 120 - mb_strlen($zusatz))).$zusatz,
                ])->save();
            }
        });
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
