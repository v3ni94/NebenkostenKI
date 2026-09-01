{{--
    Eine Kostenposition der Prüfung.

    Sichtbar sind Bezeichnung, Lieferant, Beleg- und Leistungszeitraum, Betrag,
    vorgeschlagene Kostenart als änderbares Auswahlfeld, Umlagebewertung,
    Lohnanteil nach § 35a EStG, die neutrale Quellenbezeichnung mit Seite und
    Fundstellenausschnitt, die Erkennungssicherheit und ein Hinweis auf eine
    mögliche Dublette.

    Eine Seitenansicht des Originals gibt es nicht. Die Originaldateien sind
    gelöscht.
--}}
<div class="rounded-md border border-hvm-hellgrau p-4" x-data="{ bearbeiten: false }">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="font-semibold">{{ $position->description }}</p>
            <p class="mt-1 text-sm">
                {{ $position->supplierName ?? 'Lieferant nicht erkannt' }},
                {{ $position->amountLabel }}
            </p>
            <p class="mt-1 text-sm">
                Belegdatum {{ $position->documentDateLabel ?? 'nicht erkannt' }},
                Leistungszeitraum {{ $position->servicePeriodLabel ?? 'nicht erkannt' }}
            </p>
            @if ($position->laborShareLabel !== null)
                <p class="mt-1 text-sm">
                    Ausgewiesener Lohnanteil {{ $position->laborShareLabel }} ({{ $position->paragraph35aLabel }})
                </p>
            @endif
            @if ($position->directUnitLabel !== null)
                <p class="mt-1 text-sm">Direkt zugeordnet: {{ $position->directUnitLabel }}</p>
            @endif
        </div>

        <div class="flex flex-wrap gap-2">
            <x-hvm.badge variant="{{ $position->confidenceVariant }}">{{ $position->confidenceLabel }}</x-hvm.badge>
            <x-hvm.badge variant="{{ $position->decided ? 'success' : 'neutral' }}">{{ $position->statusLabel }}</x-hvm.badge>
            <x-hvm.badge variant="{{ $position->apportionmentStatus === 'UMLAGEFAEHIG' ? 'neutral' : 'warning' }}">
                {{ $position->apportionmentLabel }}
            </x-hvm.badge>
            @if ($position->possibleDuplicate)
                <x-hvm.badge variant="warning">Mögliche Dublette</x-hvm.badge>
            @endif
        </div>
    </div>

    @include('portal.pruefung.partials.quellenhinweis', ['position' => $position])

    @if ($position->conflictReasons !== [] && ! $position->decided)
        <ul class="mt-3 space-y-1 text-sm">
            @foreach ($position->conflictReasons as $grund)
                <li>{{ $grund }}</li>
            @endforeach
        </ul>
    @endif

    <div class="mt-4 flex flex-wrap gap-2">
        <form method="POST"
              action="{{ route('portal.pruefung.kosten.bestaetigen', ['billingRun' => $billingRun->getKey(), 'costItem' => $position->id]) }}">
            @csrf
            <x-hvm.button type="submit" variant="secondary" size="sm">Bestätigen</x-hvm.button>
        </form>

        <form method="POST"
              action="{{ route('portal.pruefung.kosten.ausschliessen', ['billingRun' => $billingRun->getKey(), 'costItem' => $position->id]) }}">
            @csrf
            <x-hvm.button type="submit" variant="ghost" size="sm">Von der Umlage ausschließen</x-hvm.button>
        </form>

        <form method="POST"
              action="{{ route('portal.pruefung.kosten.verwerfen', ['billingRun' => $billingRun->getKey(), 'costItem' => $position->id]) }}">
            @csrf
            <x-hvm.button type="submit" variant="ghost" size="sm">Verwerfen</x-hvm.button>
        </form>

        <button type="button"
                class="min-h-11 rounded-md border border-transparent px-4 py-2 text-sm font-semibold underline underline-offset-4"
                x-on:click="bearbeiten = !bearbeiten">Bearbeiten</button>
    </div>

    <form method="POST" x-show="bearbeiten" x-cloak class="mt-4 grid gap-4 sm:grid-cols-2"
          action="{{ route('portal.pruefung.kosten.update', ['billingRun' => $billingRun->getKey(), 'costItem' => $position->id]) }}">
        @csrf
        @method('PUT')

        <label class="block">
            <span class="text-sm font-semibold">Bezeichnung</span>
            <input type="text" name="description" required maxlength="190" value="{{ $position->description }}"
                   class="mt-1 w-full rounded-md border border-hvm-mittelgrau px-3 py-2" />
        </label>

        <label class="block">
            <span class="text-sm font-semibold">Lieferant</span>
            <input type="text" name="supplier_name" maxlength="190" value="{{ $position->supplierName }}"
                   class="mt-1 w-full rounded-md border border-hvm-mittelgrau px-3 py-2" />
        </label>

        <label class="block">
            <span class="text-sm font-semibold">Betrag in EUR</span>
            <input type="text" name="betrag_euro" required inputmode="decimal"
                   value="{{ number_format($position->amountCent / 100, 2, ',', '.') }}"
                   class="mt-1 w-full rounded-md border border-hvm-mittelgrau px-3 py-2" />
        </label>

        <label class="block">
            <span class="text-sm font-semibold">Kostenart</span>
            <select name="cost_category_id" class="mt-1 w-full rounded-md border border-hvm-mittelgrau px-3 py-2">
                <option value="">Bitte wählen</option>
                @foreach ($kategorien as $kategorie)
                    <option value="{{ $kategorie->getKey() }}"
                        @selected($position->categoryId === (string) $kategorie->getKey())>{{ $kategorie->name }}</option>
                @endforeach
            </select>
        </label>

        <label class="block">
            <span class="text-sm font-semibold">Belegdatum</span>
            <input type="date" name="document_date"
                   class="mt-1 w-full rounded-md border border-hvm-mittelgrau px-3 py-2" />
        </label>

        <label class="block">
            <span class="text-sm font-semibold">Lohnanteil nach § 35a EStG in EUR</span>
            <input type="text" name="lohnanteil_euro" inputmode="decimal"
                   value="{{ $position->laborShareCent === null ? '' : number_format($position->laborShareCent / 100, 2, ',', '.') }}"
                   class="mt-1 w-full rounded-md border border-hvm-mittelgrau px-3 py-2" />
        </label>

        <label class="block">
            <span class="text-sm font-semibold">Leistungszeitraum von</span>
            <input type="date" name="service_period_start"
                   class="mt-1 w-full rounded-md border border-hvm-mittelgrau px-3 py-2" />
        </label>

        <label class="block">
            <span class="text-sm font-semibold">Leistungszeitraum bis</span>
            <input type="date" name="service_period_end"
                   class="mt-1 w-full rounded-md border border-hvm-mittelgrau px-3 py-2" />
        </label>

        <label class="block sm:col-span-2">
            <span class="text-sm font-semibold">Direkt einer Einheit zuordnen</span>
            <select name="direct_unit_id" class="mt-1 w-full rounded-md border border-hvm-mittelgrau px-3 py-2">
                <option value="">Keine direkte Zuordnung</option>
                @foreach ($einheiten as $einheit)
                    <option value="{{ $einheit->getKey() }}">{{ $einheit->label }}</option>
                @endforeach
            </select>
        </label>

        <label class="block sm:col-span-2">
            <span class="text-sm font-semibold">Trotz Einordnung umlegen</span>
            <span class="mt-1 flex items-start gap-2">
                <input type="checkbox" name="include_despite_status" value="1" class="mt-1" />
                <span class="text-sm">
                    Nur mit Begründung. Die Begründung wird gespeichert und ist keine juristische Freigabe.
                </span>
            </span>
        </label>

        <label class="block sm:col-span-2">
            <span class="text-sm font-semibold">Begründung</span>
            <textarea name="apportionment_override_reason" rows="2" maxlength="1000"
                      class="mt-1 w-full rounded-md border border-hvm-mittelgrau px-3 py-2"></textarea>
        </label>

        <div class="sm:col-span-2">
            <x-hvm.button type="submit" variant="secondary" size="sm">Änderungen speichern</x-hvm.button>
        </div>
    </form>
</div>
