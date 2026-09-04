@extends('layouts.portal')

@section('titel', 'Kostenprüfung')

@section('content')
    <x-hvm.section-heading
        eyebrow="Schritt 6"
        title="Kostenprüfung"
        lead="Die Kosten sind nach Kostenart gruppiert. Jede Gruppe lässt sich auf die einzelnen Belege aufklappen. Bitte bestätigen oder verwerfen Sie jede Position." />

    <div class="mt-6">
        @include('portal.wizard.partials.fortschritt', [
            'fortschritt' => $schritte,
            'billingRun' => $billingRun,
            'wiedereinstieg' => $wiedereinstieg,
        ])
    </div>

    @if (session('status'))
        <x-hvm.alert variant="success" class="mt-6">
            <p>{{ session('status') }}</p>
        </x-hvm.alert>
    @endif

    @error('weiter')
        <x-hvm.alert variant="warning" class="mt-6">
            <p>{{ $message }}</p>
        </x-hvm.alert>
    @enderror

    @foreach ($uebersicht->banners as $banner)
        @include('portal.pruefung.partials.warnbanner', ['banner' => $banner])
    @endforeach

    <x-hvm.alert variant="info" class="mt-6" label="Hinweis">
        <p>
            Eine Seitenansicht der Unterlagen ist hier nicht möglich. Ihre Originaldateien wurden nach der
            Auswertung gelöscht. Zu jedem Wert sehen Sie die neutrale Quellenbezeichnung, die Seite und einen kurzen
            Fundstellenausschnitt. Bitte vergleichen Sie zweifelhafte Werte mit Ihrer eigenen Kopie oder laden Sie
            die Unterlage erneut zur Auswertung hoch.
        </p>
    </x-hvm.alert>

    <x-hvm.card class="mt-6" title="Überblick">
        <dl class="grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="text-sm">Positionen insgesamt</dt>
                <dd class="text-lg font-semibold">{{ $uebersicht->positionCount }}</dd>
            </div>
            <div>
                <dt class="text-sm">Noch offen</dt>
                <dd class="text-lg font-semibold">{{ $uebersicht->openCount }}</dd>
            </div>
            <div>
                <dt class="text-sm">Umlagefähig, bisher bestätigt oder vorgeschlagen</dt>
                <dd class="text-lg font-semibold">{{ $uebersicht->apportionableSumLabel }}</dd>
            </div>
            <div>
                <dt class="text-sm">Getrennt ausgewiesen, nicht umgelegt</dt>
                <dd class="text-lg font-semibold">{{ $uebersicht->excludedSumLabel }}</dd>
            </div>
        </dl>
    </x-hvm.card>

    @if ($uebersicht->bulkConfirmableIds !== [])
        <form method="POST" action="{{ route('portal.pruefung.sammelbestaetigung', ['billingRun' => $billingRun->getKey()]) }}"
              class="mt-6">
            @csrf
            <x-hvm.card>
                <p>
                    {{ count($uebersicht->bulkConfirmableIds) }} Positionen sind konfliktfrei und mit hoher
                    Sicherheit erkannt. Sie können diese gemeinsam bestätigen. Nicht umlagefähige und unklare
                    Positionen bleiben davon ausgenommen und sind einzeln zu behandeln.
                </p>
                <div class="mt-4">
                    <x-hvm.button type="submit" variant="secondary">Konfliktfreie Positionen bestätigen</x-hvm.button>
                </div>
            </x-hvm.card>
        </form>
    @endif

    @if ($uebersicht->groups === [])
        <x-hvm.card class="mt-6">
            <p>Es liegt noch keine Kostenposition vor.</p>
        </x-hvm.card>
    @else
        <div class="mt-6 space-y-4">
            @foreach ($uebersicht->groups as $gruppe)
                @include('portal.pruefung.partials.gruppe', [
                    'gruppe' => $gruppe,
                    'billingRun' => $billingRun,
                    'kategorien' => $kategorien,
                    'einheiten' => $einheiten,
                ])
            @endforeach
        </div>
    @endif

    <x-hvm.card class="mt-8" title="Position manuell erfassen">
        <p class="text-sm">
            Fehlt ein Beleg in der Auswertung, erfassen Sie die Position hier. Auch eine manuell erfasste Position
            ist zunächst nur vorgeschlagen.
        </p>

        <form method="POST" action="{{ route('portal.pruefung.kosten.store', ['billingRun' => $billingRun->getKey()]) }}"
              class="mt-4 grid gap-4 sm:grid-cols-2">
            @csrf

            <label class="block">
                <span class="text-sm font-semibold">Bezeichnung</span>
                <input type="text" name="description" required maxlength="190"
                       class="mt-1 w-full rounded-md border border-hvm-mittelgrau px-3 py-2" />
            </label>

            <label class="block">
                <span class="text-sm font-semibold">Lieferant</span>
                <input type="text" name="supplier_name" maxlength="190"
                       class="mt-1 w-full rounded-md border border-hvm-mittelgrau px-3 py-2" />
            </label>

            <label class="block">
                <span class="text-sm font-semibold">Betrag in EUR</span>
                <input type="text" name="betrag_euro" required inputmode="decimal" placeholder="1.234,56"
                       class="mt-1 w-full rounded-md border border-hvm-mittelgrau px-3 py-2" />
            </label>

            <label class="block">
                <span class="text-sm font-semibold">Kostenart</span>
                <select name="cost_category_id" class="mt-1 w-full rounded-md border border-hvm-mittelgrau px-3 py-2">
                    <option value="">Bitte wählen</option>
                    @foreach ($kategorien as $kategorie)
                        <option value="{{ $kategorie->getKey() }}">{{ $kategorie->name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="text-sm font-semibold">Belegdatum</span>
                <input type="date" name="document_date"
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

            <label class="block">
                <span class="text-sm font-semibold">Lohnanteil nach § 35a EStG in EUR</span>
                <input type="text" name="lohnanteil_euro" inputmode="decimal"
                       class="mt-1 w-full rounded-md border border-hvm-mittelgrau px-3 py-2" />
            </label>

            <div class="sm:col-span-2">
                <x-hvm.button type="submit" variant="secondary">Position anlegen</x-hvm.button>
            </div>
        </form>
    </x-hvm.card>

    <div class="mt-8 flex flex-wrap items-center gap-4">
        <form method="POST" action="{{ route('portal.pruefung.weiter', ['billingRun' => $billingRun->getKey()]) }}">
            @csrf
            <x-hvm.button type="submit" variant="primary">Weiter</x-hvm.button>
        </form>

        <x-hvm.button href="{{ route('portal.pruefung.heizkosten', ['billingRun' => $billingRun->getKey()]) }}"
                      variant="ghost" size="sm">Heizkosten im Abgleich ansehen</x-hvm.button>
    </div>

    @if (! $weiterMoeglich && $sperrgrund !== null)
        <x-hvm.alert variant="warning" class="mt-4">
            <p>{{ $sperrgrund }}</p>
        </x-hvm.alert>
    @endif
@endsection
