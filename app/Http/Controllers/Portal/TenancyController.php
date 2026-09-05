<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Application\Account\AuditRecorder;
use App\Application\Account\OrganizationContext;
use App\Application\BillingRun\OccupancyTimeline;
use App\Application\Heating\EuroAmountInput;
use App\Application\Wizard\PreviewInvalidator;
use App\Domain\Period\DatePeriodRange;
use App\Enums\TenancyKind;
use App\Enums\TenancyStatus;
use App\Enums\ValueSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\OccupancyPeriodRequest;
use App\Http\Requests\Portal\TenancyRequest;
use App\Http\Requests\Portal\VacancyPeriodRequest;
use App\Models\OccupancyPeriod;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Unit;
use App\Models\VacancyPeriod;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Mietverhaeltnisse, Belegungszeitraeume und Leerstaende einer Einheit.
 *
 * Vorgabe des Masterprompts, Schritt 5:
 *
 *  - Mieter und Zustellanschrift, Einzug, Auszug, Mieterwechsel und Leerstand
 *  - keine Ueberschneidungen
 *  - lueckenlose Belegung oder ausdruecklicher Leerstand ueber den gesamten
 *    Abrechnungszeitraum, Leerstand zaehlt als Abdeckung
 *  - Personenanzahl mit Gueltigkeitszeitraeumen
 *  - bei beendeten Mietverhaeltnissen ist die Zustellanschrift Pflicht
 *
 * Die reine Feldpruefung liegt in den Formularanfragen, die Pruefung gegen den
 * gespeicherten Bestand hier, weil dafuer die Zeitachse der Einheit benoetigt
 * wird. Die taggenaue Zeitlogik selbst liegt in App\Domain\Period.
 */
class TenancyController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OccupancyTimeline $timeline,
        private readonly AuditRecorder $audit,
        private readonly PreviewInvalidator $invalidator,
    ) {}

    public function index(string $unit): View
    {
        $einheit = $this->einheit($unit);
        $this->authorize('view', $einheit);
        $this->authorize('viewAny', Tenancy::class);

        /** @var Property $objekt */
        $objekt = $einheit->property()->firstOrFail();

        $rahmen = $this->rahmen($objekt);

        return view('portal.mietverhaeltnisse.index', [
            'objekt' => $objekt,
            'einheit' => $einheit,
            'mietverhaeltnisse' => $einheit->tenancies()->with('occupancyPeriods')->orderBy('starts_on')->get(),
            'leerstaende' => $einheit->vacancyPeriods()->orderBy('starts_on')->get(),
            'rahmen' => $rahmen,
            'befunde' => $this->timeline->findings($einheit, $rahmen),
            'lueckenlos' => $this->timeline->isFullyCovered($einheit, $rahmen),
        ]);
    }

    public function create(string $unit): View
    {
        $einheit = $this->einheit($unit);
        $this->authorize('update', $einheit);
        $this->authorize('create', Tenancy::class);

        /** @var Property $objekt */
        $objekt = $einheit->property()->firstOrFail();

        return view('portal.mietverhaeltnisse.formular', [
            'objekt' => $objekt,
            'einheit' => $einheit,
            'mietverhaeltnis' => null,
        ]);
    }

    public function store(TenancyRequest $request, string $unit): RedirectResponse
    {
        $einheit = $this->einheit($unit);
        $this->authorize('update', $einheit);
        $this->authorize('create', Tenancy::class);

        $start = (string) $request->string('starts_on');
        $ende = $request->filled('ends_on') ? (string) $request->string('ends_on') : null;

        if ($this->timeline->overlapsExisting($einheit, $start, $ende)) {
            return $this->ueberschneidungsfehler($request);
        }

        /** @var Tenancy $mietverhaeltnis */
        $mietverhaeltnis = Tenancy::query()->create(array_merge(
            $this->attribute($request),
            [
                'organization_id' => $this->context->organizationId(),
                'property_id' => $einheit->getAttribute('property_id'),
                'unit_id' => $einheit->getKey(),
                'delivery_country' => 'DE',
                'contract_data_source' => ValueSource::MANUELL,
            ]
        ));

        $this->audit->record(
            action: 'tenancy.created',
            subject: $mietverhaeltnis,
            actor: $this->context->user(),
            organization: $this->context->organizationId(),
        );

        // Ein neues Mietverhaeltnis ist abrechnungsrelevant: Vorschau und
        // Bestaetigung betroffener Laeufe verlieren ihre Grundlage.
        $this->invalidator->forTenancy($mietverhaeltnis, $this->context->user());

        return redirect()
            ->route('portal.mietverhaeltnisse.index', ['unit' => $einheit->getKey()])
            ->with('status', $this->speicherhinweis($mietverhaeltnis));
    }

    public function edit(string $tenancy): View
    {
        $mietverhaeltnis = $this->mietverhaeltnis($tenancy);
        $this->authorize('update', $mietverhaeltnis);

        /** @var Unit $einheit */
        $einheit = $mietverhaeltnis->unit()->firstOrFail();
        /** @var Property $objekt */
        $objekt = $einheit->property()->firstOrFail();

        return view('portal.mietverhaeltnisse.formular', [
            'objekt' => $objekt,
            'einheit' => $einheit,
            'mietverhaeltnis' => $mietverhaeltnis,
        ]);
    }

    public function update(TenancyRequest $request, string $tenancy): RedirectResponse
    {
        $mietverhaeltnis = $this->mietverhaeltnis($tenancy);
        $this->authorize('update', $mietverhaeltnis);

        /** @var Unit $einheit */
        $einheit = $mietverhaeltnis->unit()->firstOrFail();

        $start = (string) $request->string('starts_on');
        $ende = $request->filled('ends_on') ? (string) $request->string('ends_on') : null;
        $eigeneId = $mietverhaeltnis->getKey();

        if ($this->timeline->overlapsExisting(
            $einheit,
            $start,
            $ende,
            is_string($eigeneId) ? $eigeneId : null
        )) {
            return $this->ueberschneidungsfehler($request);
        }

        // Bisheriger Vertragszeitraum, damit auch ein Lauf invalidiert wird,
        // den das Mietverhaeltnis nach der Aenderung nicht mehr beruehrt.
        $bisher = [
            $this->iso($mietverhaeltnis->getAttribute('starts_on')),
            $this->iso($mietverhaeltnis->getAttribute('ends_on')),
        ];

        $mietverhaeltnis->fill($this->attribute($request))->save();

        $this->audit->record(
            action: 'tenancy.updated',
            subject: $mietverhaeltnis,
            actor: $this->context->user(),
            organization: $this->context->organizationId(),
        );

        $this->invalidator->forTenancy($mietverhaeltnis, $this->context->user(), $bisher);

        return redirect()
            ->route('portal.mietverhaeltnisse.index', ['unit' => $einheit->getKey()])
            ->with('status', $this->speicherhinweis($mietverhaeltnis));
    }

    public function destroy(string $tenancy): RedirectResponse
    {
        $mietverhaeltnis = $this->mietverhaeltnis($tenancy);
        $this->authorize('delete', $mietverhaeltnis);

        $einheitId = $mietverhaeltnis->getAttribute('unit_id');

        $mietverhaeltnis->delete();

        $this->audit->record(
            action: 'tenancy.deleted',
            subject: $mietverhaeltnis,
            actor: $this->context->user(),
            organization: $this->context->organizationId(),
        );

        $this->invalidator->forTenancy($mietverhaeltnis, $this->context->user());

        return redirect()
            ->route('portal.mietverhaeltnisse.index', ['unit' => $einheitId])
            ->with('status', 'Das Mietverhältnis ist entfernt.');
    }

    /**
     * Leerstandszeitraum anlegen.
     */
    public function storeVacancy(VacancyPeriodRequest $request, string $unit): RedirectResponse
    {
        $einheit = $this->einheit($unit);
        $this->authorize('update', $einheit);

        $start = (string) $request->string('starts_on');
        $ende = (string) $request->string('ends_on');

        if ($this->timeline->vacancyOverlapsExisting($einheit, $start, $ende)) {
            return back()
                ->withInput()
                ->withErrors([
                    'starts_on' => 'Dieser Leerstand überschneidet sich mit einem bestehenden Eintrag '
                        .'für dieselbe Einheit. Bitte passen Sie die Zeiträume an.',
                ]);
        }

        /** @var VacancyPeriod $leerstand */
        $leerstand = VacancyPeriod::query()->create([
            'organization_id' => $this->context->organizationId(),
            'unit_id' => $einheit->getKey(),
            'starts_on' => $start,
            'ends_on' => $ende,
            'reason' => $request->input('reason'),
        ]);

        $this->audit->record(
            action: 'vacancy_period.created',
            subject: $leerstand,
            actor: $this->context->user(),
            organization: $this->context->organizationId(),
        );

        $this->invalidator->forUnit($einheit, $this->context->user());

        return redirect()
            ->route('portal.mietverhaeltnisse.index', ['unit' => $einheit->getKey()])
            ->with('status', 'Der Leerstand ist gespeichert. Leerstandskosten bleiben beim Eigentümer.');
    }

    public function destroyVacancy(string $vacancy): RedirectResponse
    {
        /** @var VacancyPeriod $leerstand */
        $leerstand = $this->context->vacancyPeriods()->findOrFail($vacancy);

        $einheitId = $leerstand->getAttribute('unit_id');
        $einheit = $this->einheit(is_string($einheitId) ? $einheitId : '');
        $this->authorize('update', $einheit);

        $leerstand->delete();

        $this->audit->record(
            action: 'vacancy_period.deleted',
            subject: $leerstand,
            actor: $this->context->user(),
            organization: $this->context->organizationId(),
        );

        $this->invalidator->forUnit($einheit, $this->context->user());

        return redirect()
            ->route('portal.mietverhaeltnisse.index', ['unit' => $einheitId])
            ->with('status', 'Der Leerstand ist entfernt.');
    }

    /**
     * Personenanzahl mit Gueltigkeitszeitraum anlegen.
     */
    public function storeOccupancy(OccupancyPeriodRequest $request, string $tenancy): RedirectResponse
    {
        $mietverhaeltnis = $this->mietverhaeltnis($tenancy);
        $this->authorize('update', $mietverhaeltnis);

        $start = (string) $request->string('starts_on');
        $ende = (string) $request->string('ends_on');

        $fehler = $this->belegungFehler($mietverhaeltnis, $start, $ende);

        if ($fehler !== null) {
            return back()->withInput()->withErrors(['starts_on' => $fehler]);
        }

        /** @var OccupancyPeriod $belegung */
        $belegung = OccupancyPeriod::query()->create([
            'organization_id' => $this->context->organizationId(),
            'tenancy_id' => $mietverhaeltnis->getKey(),
            'starts_on' => $start,
            'ends_on' => $ende,
            'person_count' => $request->integer('person_count'),
            'source' => ValueSource::MANUELL,
        ]);

        $this->audit->record(
            action: 'occupancy_period.created',
            subject: $belegung,
            actor: $this->context->user(),
            organization: $this->context->organizationId(),
        );

        $this->invalidator->forTenancy($mietverhaeltnis, $this->context->user());

        return redirect()
            ->route('portal.mietverhaeltnisse.index', ['unit' => $mietverhaeltnis->getAttribute('unit_id')])
            ->with('status', 'Der Zeitraum mit der Personenanzahl ist gespeichert.');
    }

    public function destroyOccupancy(string $occupancy): RedirectResponse
    {
        /** @var OccupancyPeriod $belegung */
        $belegung = $this->context->occupancyPeriods()->findOrFail($occupancy);

        $mietId = $belegung->getAttribute('tenancy_id');
        $mietverhaeltnis = $this->mietverhaeltnis(is_string($mietId) ? $mietId : '');
        $this->authorize('update', $mietverhaeltnis);

        $belegung->delete();

        $this->audit->record(
            action: 'occupancy_period.deleted',
            subject: $belegung,
            actor: $this->context->user(),
            organization: $this->context->organizationId(),
        );

        $this->invalidator->forTenancy($mietverhaeltnis, $this->context->user());

        return redirect()
            ->route('portal.mietverhaeltnisse.index', ['unit' => $mietverhaeltnis->getAttribute('unit_id')])
            ->with('status', 'Der Zeitraum ist entfernt.');
    }

    private function ueberschneidungsfehler(TenancyRequest $request): RedirectResponse
    {
        return back()
            ->withInput()
            ->withErrors([
                'starts_on' => 'Dieser Zeitraum überschneidet sich mit einem bestehenden Mietverhältnis '
                    .'oder Leerstand derselben Einheit. Für eine Einheit darf zu jedem Tag nur ein Eintrag gelten.',
            ]);
    }

    /**
     * Prueft einen Belegungszeitraum gegen das Mietverhaeltnis und gegen die
     * bereits erfassten Zeitraeume.
     */
    private function belegungFehler(Tenancy $mietverhaeltnis, string $start, string $ende): ?string
    {
        $mietStart = $this->iso($mietverhaeltnis->getAttribute('starts_on'));
        $mietEnde = $this->iso($mietverhaeltnis->getAttribute('ends_on'));

        if ($mietStart !== null && $start < $mietStart) {
            return 'Der Zeitraum darf nicht vor dem Einzug beginnen.';
        }

        if ($mietEnde !== null && $ende > $mietEnde) {
            return 'Der Zeitraum darf nicht nach dem Auszug enden.';
        }

        $neu = DatePeriodRange::fromIso($start, $ende);

        foreach ($mietverhaeltnis->occupancyPeriods()->get() as $bestehend) {
            $von = $this->iso($bestehend->getAttribute('starts_on'));
            $bis = $this->iso($bestehend->getAttribute('ends_on'));

            if ($von === null || $bis === null) {
                continue;
            }

            if ($neu->overlaps(DatePeriodRange::fromIso($von, $bis))) {
                return 'Dieser Zeitraum überschneidet sich mit einem bereits erfassten Zeitraum '
                    .'für dieselbe Personenanzahl. Bitte passen Sie die Zeiträume an.';
            }
        }

        return null;
    }

    private function speicherhinweis(Tenancy $mietverhaeltnis): string
    {
        if ($mietverhaeltnis->getAttribute('kind') === TenancyKind::GEWERBE) {
            return 'Das Mietverhältnis ist gespeichert. Hinweis: Gewerbliche Mietverhältnisse werden nicht '
                .'automatisch finalisiert. Bitte prüfen Sie die Umlagevereinbarung und die umsatzsteuerliche '
                .'Behandlung gesondert.';
        }

        return 'Das Mietverhältnis ist gespeichert.';
    }

    private function einheit(string $id): Unit
    {
        /** @var Unit $einheit */
        $einheit = $this->context->units()->findOrFail($id);

        return $einheit;
    }

    private function mietverhaeltnis(string $id): Tenancy
    {
        /** @var Tenancy $mietverhaeltnis */
        $mietverhaeltnis = $this->context->tenancies()->findOrFail($id);

        return $mietverhaeltnis;
    }

    /**
     * Rahmenzeitraum fuer die Zeitachsenpruefung.
     */
    private function rahmen(Property $objekt): DatePeriodRange
    {
        $lauf = $objekt->billingRuns()->orderByDesc('period_start')->first();

        if ($lauf !== null) {
            $von = $this->iso($lauf->getAttribute('period_start'));
            $bis = $this->iso($lauf->getAttribute('period_end'));

            if ($von !== null && $bis !== null) {
                return DatePeriodRange::fromIso($von, $bis);
            }
        }

        return DatePeriodRange::calendarYear((int) now()->format('Y') - 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function attribute(TenancyRequest $request): array
    {
        $ende = $request->filled('ends_on') ? (string) $request->string('ends_on') : null;

        /** @var array<string, mixed> $werte */
        $werte = $request->safe()->only([
            'tenant_display_name',
            'kind',
            'starts_on',
            'delivery_address_line',
            'delivery_address_extra',
            'delivery_postal_code',
            'delivery_city',
            'notes',
        ]);

        $werte['ends_on'] = $ende;
        $werte['status'] = $ende === null ? TenancyStatus::AKTIV : TenancyStatus::BEENDET;
        $werte['heating_prepayment_separate'] = $request->boolean('heating_prepayment_separate');

        $werte['monthly_operating_prepayment_cent'] = $this->cent(
            $request->input('monthly_operating_prepayment_eur')
        );
        $werte['monthly_heating_prepayment_cent'] = $this->cent(
            $request->input('monthly_heating_prepayment_eur')
        );

        $werte['operating_costs_apportionment_agreed'] = $this->jaNeinOderUnbekannt(
            $request->input('operating_costs_apportionment_agreed')
        );
        $werte['other_operating_costs_agreed'] = $this->jaNeinOderUnbekannt(
            $request->input('other_operating_costs_agreed')
        );

        return $werte;
    }

    /**
     * Null bedeutet ausdruecklich unbekannt. Es wird niemals eine Vereinbarung
     * unterstellt (ARCHITECTURE.md Grundsatz 5).
     */
    private function jaNeinOderUnbekannt(mixed $wert): ?bool
    {
        if ($wert === null || $wert === '') {
            return null;
        }

        return $wert === '1' || $wert === 1 || $wert === true;
    }

    /**
     * Wandelt einen Eurobetrag als Zeichenkette in ganze Cent.
     *
     * Es wird ausdruecklich nicht ueber float gerechnet (ARCHITECTURE.md
     * Grundsatz 8). Die Umrechnung laeuft zentral ueber EuroAmountInput; die
     * Gueltigkeit der Eingabe ist durch TenancyRequest bereits sichergestellt.
     */
    private function cent(mixed $eur): ?int
    {
        if (is_int($eur)) {
            $eur = (string) $eur;
        }

        if (! is_string($eur)) {
            return null;
        }

        return EuroAmountInput::parse($eur)?->cents;
    }

    private function iso(mixed $wert): ?string
    {
        if ($wert instanceof Carbon) {
            return $wert->toDateString();
        }

        if (is_string($wert) && $wert !== '') {
            return substr($wert, 0, 10);
        }

        return null;
    }
}
