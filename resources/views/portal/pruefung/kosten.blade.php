@extends('layouts.portal')

@section('titel', 'Kostenprüfung')

@section('content')
    <x-hvm.page-header
        eyebrow="Schritt 6 von 10"
        title="Kostenprüfung"
        lead="Die Kosten sind nach Kostenart gruppiert. Jede Gruppe lässt sich auf die einzelnen Belege aufklappen. Bitte bestätigen oder verwerfen Sie jede Position." />

    <div class="mt-8">
        @include('portal.wizard.partials.fortschritt', [
            'fortschritt' => $schritte,
            'billingRun' => $billingRun,
            'wiedereinstieg' => $wiedereinstieg,
        ])
    </div>

    @if (session('status'))
        <x-hvm.alert variant="success" class="mt-8">
            <p>{{ session('status') }}</p>
        </x-hvm.alert>
    @endif

    @error('weiter')
        <x-hvm.alert variant="warning" class="mt-8">
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

    {{-- Ueberblick als Kennzahlenreihe --------------------------------------- --}}

    <x-hvm.card class="mt-10" title="Überblick" eyebrow="Zwischenstand">
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-hvm.stat size="sm" tone="canvas" :icon="false" label="Positionen insgesamt" :value="$uebersicht->positionCount" />
            <x-hvm.stat size="sm" tone="canvas" :icon="false" label="Noch offen" :value="$uebersicht->openCount" />
            <x-hvm.stat size="sm" tone="canvas" :icon="false" label="Umlagefähig, bisher bestätigt oder vorgeschlagen" :value="$uebersicht->apportionableSumLabel" />
            <x-hvm.stat size="sm" tone="canvas" :icon="false" label="Getrennt ausgewiesen, nicht umgelegt" :value="$uebersicht->excludedSumLabel" />
        </div>
    </x-hvm.card>

    @if ($uebersicht->bulkConfirmableIds !== [])
        <form method="POST" action="{{ route('portal.pruefung.sammelbestaetigung', ['billingRun' => $billingRun->getKey()]) }}"
              class="mt-6">
            @csrf
            <x-hvm.card tone="canvas">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex min-w-0 gap-4">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark" aria-hidden="true">
                            <x-hvm.icon name="sparkle" class="h-5 w-5" />
                        </span>
                        <p class="max-w-prose text-sm leading-relaxed text-hvm-textschwarz">
                            {{ count($uebersicht->bulkConfirmableIds) }} Positionen sind konfliktfrei und mit hoher
                            Sicherheit erkannt. Sie können diese gemeinsam bestätigen. Nicht umlagefähige und unklare
                            Positionen bleiben davon ausgenommen und sind einzeln zu behandeln.
                        </p>
                    </div>
                    <div class="lg:shrink-0">
                        <x-hvm.button type="submit" variant="secondary">
                            <x-hvm.icon name="check" class="h-4 w-4" />
                            Konfliktfreie Positionen bestätigen
                        </x-hvm.button>
                    </div>
                </div>
            </x-hvm.card>
        </form>
    @endif

    {{-- Kostengruppen ---------------------------------------------------------- --}}

    <section class="mt-16" aria-labelledby="ueberschrift-kostengruppen">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">Nach Kostenart</p>
                <h2 id="ueberschrift-kostengruppen" class="mt-1 text-2xl font-semibold tracking-tight text-hvm-textschwarz">Kostengruppen</h2>
            </div>
        </div>

        @if ($uebersicht->groups === [])
            <x-hvm.empty-state class="mt-6" icon="receipt" title="Noch keine Kostenposition">
                <p>Es liegt noch keine Kostenposition vor.</p>
            </x-hvm.empty-state>
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
    </section>

    {{-- Manuelle Erfassung --------------------------------------------------- --}}

    <section class="mt-16" aria-labelledby="ueberschrift-manuell">
        <div class="max-w-3xl">
            <p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">Ergänzung</p>
            <h2 id="ueberschrift-manuell" class="mt-1 text-2xl font-semibold tracking-tight text-hvm-textschwarz">Position manuell erfassen</h2>
            <p class="mt-2 max-w-prose text-base leading-relaxed text-hvm-text-sekundaer">
                Fehlt ein Beleg in der Auswertung, erfassen Sie die Position hier. Auch eine manuell erfasste Position
                ist zunächst nur vorgeschlagen.
            </p>
        </div>

        <x-hvm.card :kennlinie="true" padding="none" class="mt-6 rounded-3xl">
            <form method="POST" action="{{ route('portal.pruefung.kosten.store', ['billingRun' => $billingRun->getKey()]) }}"
                  class="space-y-6 p-6 sm:p-8">
                @csrf

                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <x-hvm.field name="description" id="manuell-description" label="Bezeichnung" :required="true" maxlength="190" />
                    <x-hvm.field name="supplier_name" id="manuell-supplier_name" label="Lieferant" maxlength="190" />
                    <x-hvm.field name="betrag_euro" id="manuell-betrag_euro" label="Betrag in EUR" :required="true" inputmode="decimal" placeholder="1.234,56" />
                    <x-hvm.field name="cost_category_id" id="manuell-cost_category_id" label="Kostenart" type="select">
                        <option value="">Bitte wählen</option>
                        @foreach ($kategorien as $kategorie)
                            <option value="{{ $kategorie->getKey() }}" @selected((string) old('cost_category_id') === (string) $kategorie->getKey())>{{ $kategorie->name }}</option>
                        @endforeach
                    </x-hvm.field>
                    <x-hvm.field name="document_date" id="manuell-document_date" label="Belegdatum" type="date" />
                    <x-hvm.field name="service_period_start" id="manuell-service_period_start" label="Leistungszeitraum von" type="date" />
                    <x-hvm.field name="service_period_end" id="manuell-service_period_end" label="Leistungszeitraum bis" type="date" />
                    <x-hvm.field name="lohnanteil_euro" id="manuell-lohnanteil_euro" label="Lohnanteil nach § 35a EStG in EUR" inputmode="decimal" />
                </div>

                <div class="flex flex-wrap gap-3">
                    <x-hvm.button type="submit" variant="secondary">
                        <x-hvm.icon name="plus" class="h-4 w-4" />
                        Position anlegen
                    </x-hvm.button>
                </div>
            </form>
        </x-hvm.card>
    </section>

    {{-- Weiter ------------------------------------------------------------------ --}}

    <div class="mt-16 flex flex-wrap items-center gap-3">
        <form method="POST" action="{{ route('portal.pruefung.weiter', ['billingRun' => $billingRun->getKey()]) }}">
            @csrf
            <x-hvm.button type="submit" variant="primary">
                Weiter
                <x-hvm.icon name="arrow-right" class="h-4 w-4" />
            </x-hvm.button>
        </form>

        <x-hvm.button href="{{ route('portal.pruefung.heizkosten', ['billingRun' => $billingRun->getKey()]) }}"
                      variant="ghost">Heizkosten im Abgleich ansehen</x-hvm.button>
    </div>

    @if (! $weiterMoeglich && $sperrgrund !== null)
        <x-hvm.alert variant="warning" class="mt-6">
            <p>{{ $sperrgrund }}</p>
        </x-hvm.alert>
    @endif
@endsection
