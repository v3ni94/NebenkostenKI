{{--
    Eine Kostenposition der Prüfung.

    Sichtbar sind Bezeichnung, Lieferant, Beleg- und Leistungszeitraum, Betrag,
    vorgeschlagene Kostenart als änderbares Auswahlfeld, Umlagebewertung,
    Lohnanteil nach § 35a EStG, die neutrale Quellenbezeichnung mit Seite und
    Fundstellenausschnitt, die Erkennungssicherheit und ein Hinweis auf eine
    mögliche Dublette.

    Eine Seitenansicht des Originals gibt es nicht. Die Originaldateien sind
    gelöscht.

    Die Feld-IDs tragen die Positionskennung als Praefix, weil mehrere
    Positionen mit gleichen Feldnamen auf einer Seite stehen.
--}}
@php
    $feldId = fn (string $name): string => 'position-'.$position->id.'-'.$name;
@endphp
<article class="rounded-2xl border border-hvm-linie bg-white p-5 sm:p-6" x-data="{ bearbeiten: false }">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0 flex-1">
            <h4 class="text-base font-semibold tracking-tight text-hvm-textschwarz">{{ $position->description }}</h4>
            <p class="mt-1 text-sm text-hvm-text-sekundaer">
                {{ $position->supplierName ?? 'Lieferant nicht erkannt' }},
                <span class="font-semibold text-hvm-textschwarz tabular whitespace-nowrap">{{ $position->amountLabel }}</span>
            </p>
            <p class="mt-1 text-sm text-hvm-text-sekundaer">
                Belegdatum {{ $position->documentDateLabel ?? 'nicht erkannt' }},
                Leistungszeitraum {{ $position->servicePeriodLabel ?? 'nicht erkannt' }}
            </p>
            @if ($position->laborShareLabel !== null)
                <p class="mt-1 text-sm text-hvm-text-sekundaer">
                    Ausgewiesener Lohnanteil {{ $position->laborShareLabel }} ({{ $position->paragraph35aLabel }})
                </p>
            @endif
            @if ($position->directUnitLabel !== null)
                <p class="mt-1 text-sm text-hvm-text-sekundaer">Direkt zugeordnet: {{ $position->directUnitLabel }}</p>
            @endif
        </div>

        <div class="flex flex-wrap gap-2 lg:max-w-xs lg:shrink-0 lg:justify-end">
            <x-hvm.badge variant="{{ $position->confidenceVariant }}" icon="search">{{ $position->confidenceLabel }}</x-hvm.badge>
            <x-hvm.badge variant="{{ $position->decided ? 'success' : 'neutral' }}" :icon="$position->decided ? null : 'clock'">{{ $position->statusLabel }}</x-hvm.badge>
            <x-hvm.badge variant="{{ $position->apportionmentStatus === 'UMLAGEFAEHIG' ? 'neutral' : 'warning' }}">
                {{ $position->apportionmentLabel }}
            </x-hvm.badge>
            @if ($position->possibleDuplicate)
                <x-hvm.badge variant="warning" icon="layers">Mögliche Dublette</x-hvm.badge>
            @endif
        </div>
    </div>

    @include('portal.pruefung.partials.quellenhinweis', ['position' => $position])

    @if ($position->conflictReasons !== [] && ! $position->decided)
        <ul class="mt-4 space-y-2 text-sm leading-relaxed text-hvm-textschwarz">
            @foreach ($position->conflictReasons as $grund)
                <li class="flex gap-2">
                    <span class="mt-0.5 shrink-0 text-status-warning" aria-hidden="true">
                        <x-hvm.icon name="warning" class="h-4 w-4" />
                    </span>
                    <span>{{ $grund }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="mt-5 flex flex-wrap gap-2 border-t border-hvm-linie pt-5">
        <form method="POST"
              action="{{ route('portal.pruefung.kosten.bestaetigen', ['billingRun' => $billingRun->getKey(), 'costItem' => $position->id]) }}">
            @csrf
            <x-hvm.button type="submit" variant="secondary" size="sm">
                <x-hvm.icon name="check" class="h-4 w-4" />
                Bestätigen
            </x-hvm.button>
        </form>

        <form method="POST"
              action="{{ route('portal.pruefung.kosten.ausschliessen', ['billingRun' => $billingRun->getKey(), 'costItem' => $position->id]) }}">
            @csrf
            <x-hvm.button type="submit" variant="ghost" size="sm">Von der Umlage ausschließen</x-hvm.button>
        </form>

        <x-hvm.button type="button" variant="secondary" size="sm"
                      x-on:click="bearbeiten = !bearbeiten"
                      x-bind:aria-expanded="bearbeiten ? 'true' : 'false'"
                      aria-expanded="false"
                      aria-controls="{{ $feldId('formular') }}">Bearbeiten</x-hvm.button>

        {{-- Destruktive Handlung als danger mit Symbol, nie als Textlink (Designsystem 3, 4.12). --}}
        <form method="POST"
              action="{{ route('portal.pruefung.kosten.verwerfen', ['billingRun' => $billingRun->getKey(), 'costItem' => $position->id]) }}">
            @csrf
            <x-hvm.button type="submit" variant="danger" size="sm">
                <x-hvm.icon name="trash" class="h-4 w-4" />
                Verwerfen
            </x-hvm.button>
        </form>
    </div>

    <form method="POST" x-show="bearbeiten" x-cloak id="{{ $feldId('formular') }}"
          class="mt-5 space-y-6 rounded-2xl bg-hvm-canvas p-4 sm:p-5"
          action="{{ route('portal.pruefung.kosten.update', ['billingRun' => $billingRun->getKey(), 'costItem' => $position->id]) }}">
        @csrf
        @method('PUT')

        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 sm:items-end">
            <x-hvm.field name="description" :id="$feldId('description')" label="Bezeichnung" :required="true" maxlength="190" :value="$position->description" />
            <x-hvm.field name="supplier_name" :id="$feldId('supplier_name')" label="Lieferant" maxlength="190" :value="$position->supplierName" />
            <x-hvm.field name="betrag_euro" :id="$feldId('betrag_euro')" label="Betrag in EUR" :required="true" inputmode="decimal"
                         :value="number_format($position->amountCent / 100, 2, ',', '.')" />
            <x-hvm.field name="cost_category_id" :id="$feldId('cost_category_id')" label="Kostenart" type="select">
                <option value="">Bitte wählen</option>
                @foreach ($kategorien as $kategorie)
                    <option value="{{ $kategorie->getKey() }}"
                        @selected($position->categoryId === (string) $kategorie->getKey())>{{ $kategorie->name }}</option>
                @endforeach
            </x-hvm.field>
            <x-hvm.field name="document_date" :id="$feldId('document_date')" label="Belegdatum" type="date" value="" />
            <x-hvm.field name="lohnanteil_euro" :id="$feldId('lohnanteil_euro')" label="Lohnanteil nach § 35a EStG in EUR" inputmode="decimal"
                         :value="$position->laborShareCent === null ? '' : number_format($position->laborShareCent / 100, 2, ',', '.')" />
            <x-hvm.field name="service_period_start" :id="$feldId('service_period_start')" label="Leistungszeitraum von" type="date" value="" />
            <x-hvm.field name="service_period_end" :id="$feldId('service_period_end')" label="Leistungszeitraum bis" type="date" value="" />

            <x-hvm.field wrapperClass="sm:col-span-2" name="direct_unit_id" :id="$feldId('direct_unit_id')" label="Direkt einer Einheit zuordnen" type="select">
                <option value="">Keine direkte Zuordnung</option>
                @foreach ($einheiten as $einheit)
                    <option value="{{ $einheit->getKey() }}">{{ $einheit->label }}</option>
                @endforeach
            </x-hvm.field>

            <x-hvm.field wrapperClass="sm:col-span-2" name="include_despite_status" :id="$feldId('include_despite_status')" label="Trotz Einordnung umlegen" type="checkbox" value="1"
                         :checked="false"
                         hint="Nur mit Begründung. Die Begründung wird gespeichert und ist keine juristische Freigabe." />

            <x-hvm.field wrapperClass="sm:col-span-2" name="apportionment_override_reason" :id="$feldId('apportionment_override_reason')" label="Begründung" type="textarea" rows="2" maxlength="1000" value="" />
        </div>

        <div class="flex flex-wrap gap-3">
            <x-hvm.button type="submit" variant="secondary" size="sm">Änderungen speichern</x-hvm.button>
        </div>
    </form>
</article>
