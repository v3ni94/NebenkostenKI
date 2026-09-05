@extends('layouts.portal')

@section('titel', 'Verteilerschlüssel und Verbrauch')

@section('content')
    <x-hvm.page-header
        :eyebrow="$schritt->eyebrow()"
        title="Verteilerschlüssel und Verbrauch"
        lead="Je Kostenart legen Sie den Schlüssel fest. Wir zeigen Werte je Einheit, Nenner und Rechenweg." />

    <div class="mt-8">
        @include('portal.wizard.partials.fortschritt', [
            'fortschritt' => $fortschritt,
            'billingRun' => $billingRun,
            'wiedereinstieg' => $wiedereinstieg,
        ])
    </div>

    <div class="mt-8 space-y-4">
        <x-hvm.alert variant="info" label="Hinweis">
            <p>
                Der WEG-Schlüssel und der mietvertragliche Umlageschlüssel sind nicht dasselbe. Wir setzen beide nicht
                gleich. Bitte prüfen Sie die Mietvertragsregelung.
            </p>
        </x-hvm.alert>

        @if ($blocker !== [])
            <x-hvm.alert variant="error" label="Blockiert die Abrechnung">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($blocker as $grund)
                        <li>{{ $grund }}</li>
                    @endforeach
                </ul>
            </x-hvm.alert>
        @endif

        @if ($warnungen !== [])
            <x-hvm.alert variant="warning" label="Bitte prüfen">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($warnungen as $warnung)
                        <li>{{ $warnung }}</li>
                    @endforeach
                </ul>
            </x-hvm.alert>
        @endif
    </div>

    @if ($ersatzverteilung !== [])
        <x-hvm.card class="mt-8" title="Verbrauch ohne Zwischenablesung" eyebrow="Entscheidung erforderlich">
            <p class="max-w-prose">
                Für die folgenden Einheiten liegt bei einem Nutzerwechsel keine Zwischenablesung vor. Wir schätzen
                nicht still. Sie können eine taggenaue Ersatzverteilung ausdrücklich bestätigen; sie wird in der
                Abrechnung gekennzeichnet.
            </p>

            <ul class="mt-5 divide-y divide-hvm-linie rounded-2xl border border-hvm-linie">
                @foreach ($ersatzverteilung as $einheit)
                    <li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                        <span class="font-semibold text-hvm-textschwarz">{{ $einheit->label }}</span>
                        <form method="POST"
                              action="{{ route('portal.wizard.schluessel.ersatzverteilung', ['billingRun' => $billingRun->getKey(), 'unit' => $einheit->getKey()]) }}">
                            @csrf
                            <x-hvm.button type="submit" variant="secondary" size="sm">
                                Ersatzverteilung ausdrücklich bestätigen
                            </x-hvm.button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </x-hvm.card>
    @endif

    <form method="POST" class="mt-10 space-y-4"
          action="{{ route('portal.wizard.schluessel.speichern', ['billingRun' => $billingRun->getKey()]) }}">
        @csrf

        <h2 class="sr-only">Verteilerschlüssel je Kostenart</h2>

        @foreach ($zeilen as $zeile)
            @php
                $feldId = 'schluessel-'.$zeile->categoryId;
                $direktzuordnung = $zeile->keyType === \App\Enums\AllocationKeyType::DIREKT;
                $wertspalte = $direktzuordnung ? 'Betrag in EUR' : 'Zähler';
            @endphp
            <x-hvm.card padding="none">
                <div class="p-5 sm:p-6">
                    <h3 class="text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl">{{ $zeile->categoryLabel }}</h3>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <x-hvm.badge variant="info" icon="document">Quelle: {{ $zeile->sourceBadge() }}</x-hvm.badge>
                        <x-hvm.badge variant="neutral">
                            Summe der Anteile: {{ $zeile->sharePercent }} Prozent
                        </x-hvm.badge>
                        <x-hvm.badge variant="neutral">Nenner: {{ $zeile->denominator }}</x-hvm.badge>
                    </div>

                    @if ($zeile->defaultWarning !== null)
                        <p class="mt-4 flex items-start gap-1.5 text-sm font-medium text-status-warning">
                            <x-hvm.icon name="warning" class="mt-0.5 h-4 w-4" />
                            <span>{{ $zeile->defaultWarning }}</span>
                        </p>
                    @endif

                    @if ($zeile->deviationWarning() !== null)
                        <p class="mt-3 flex items-start gap-1.5 text-sm font-medium text-status-warning">
                            <x-hvm.icon name="warning" class="mt-0.5 h-4 w-4" />
                            <span>{{ $zeile->deviationWarning() }}</span>
                        </p>
                    @endif

                    @if ($zeile->shareWarning() !== null)
                        <p class="mt-3 flex items-start gap-1.5 text-sm font-medium text-status-error">
                            <x-hvm.icon name="alert" class="mt-0.5 h-4 w-4" />
                            <span>{{ $zeile->shareWarning() }}</span>
                        </p>
                    @endif

                    <div class="mt-6 grid grid-cols-1 gap-6 sm:grid-cols-3 sm:items-end">
                        <x-hvm.field name="kostenarten[{{ $zeile->categoryId }}][key_type]" :id="$feldId.'-typ'" label="Verteilerschlüssel" type="select">
                            @foreach ($schluesseltypen as $typ)
                                <option value="{{ $typ->value }}" @selected($typ === $zeile->keyType)>
                                    {{ $typ->label() }}
                                </option>
                            @endforeach
                        </x-hvm.field>

                        @unless ($direktzuordnung)
                            <x-hvm.field name="kostenarten[{{ $zeile->categoryId }}][nenner]" :id="$feldId.'-nenner'" label="Nenner, optional abweichend"
                                         inputmode="decimal" :value="$zeile->denominator" class="tabular" />
                        @endunless

                        <x-hvm.field name="kostenarten[{{ $zeile->categoryId }}][masseinheit]" :id="$feldId.'-einheit'" label="Maßeinheit, optional"
                                     :value="$zeile->measurementUnit" />
                    </div>

                    @if ($direktzuordnung)
                        <p class="mt-4 max-w-prose text-sm leading-relaxed text-hvm-text-sekundaer">
                            Bei der Direktzuordnung tragen Sie je Mietverhältnis den Betrag in Euro ein, zum Beispiel
                            300,50. Die Summe der Beträge darf den Positionsbetrag der Kostenart nicht übersteigen; ein
                            nicht zugeordneter Rest verbleibt beim Eigentümer. Der Nenner ergibt sich aus dem
                            Positionsbetrag und wird nicht eingegeben.
                        </p>
                    @endif
                </div>

                <div class="border-t border-hvm-linie">
                    <table class="hvm-table hvm-table-stack text-base">
                        <caption class="sr-only">Werte je {{ $zeile->isUnitScope ? 'Einheit' : 'Mietverhältnis' }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ $zeile->isUnitScope ? 'Einheit' : 'Mietverhältnis' }}</th>
                                <th scope="col">{{ $wertspalte }}</th>
                                <th scope="col">Herkunft</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($zeile->values as $wert)
                                @php
                                    $wertId = $feldId.'-wert-'.$wert->participantId;
                                @endphp
                                <tr>
                                    <th scope="row" class="font-medium">{{ $wert->label }}</th>
                                    <td data-label="{{ $wertspalte }}">
                                        <label for="{{ $wertId }}" class="sr-only">{{ $wertspalte }} für {{ $wert->label }}</label>
                                        <input type="text" inputmode="decimal"
                                               id="{{ $wertId }}"
                                               name="kostenarten[{{ $zeile->categoryId }}][werte][{{ $wert->participantId }}]"
                                               value="{{ $wert->value }}"
                                               @if ($wert->isMissing()) aria-invalid="true" aria-describedby="{{ $wertId }}-fehler" @endif
                                               @class([
                                                   'hvm-input text-right tabular sm:max-w-40',
                                                   'border-status-error' => $wert->isMissing(),
                                               ])>
                                        @if ($wert->isMissing())
                                            <p id="{{ $wertId }}-fehler" class="mt-2 flex items-start gap-1.5 text-sm font-medium text-status-error">
                                                <x-hvm.icon name="alert" class="mt-0.5 h-4 w-4" />
                                                <span>{{ $wert->missingText() }}</span>
                                            </p>
                                        @endif
                                    </td>
                                    <td data-label="Herkunft" class="text-hvm-text-sekundaer">{{ $wert->herkunft ?? 'nicht angegeben' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-hvm.card>
        @endforeach

        {{-- Buttonreihe (4.12): Speichern und Weiter nebeneinander, das zweite Formular ist per form-Attribut angebunden. --}}
        <div class="flex flex-wrap gap-3 pt-4">
            <x-hvm.button type="submit" variant="primary">Verteilerschlüssel speichern</x-hvm.button>
            <x-hvm.button type="submit" variant="secondary" form="schluessel-weiter">
                Weiter zum Prüfbericht
                <x-hvm.icon name="arrow-right" class="h-4 w-4" />
            </x-hvm.button>
        </div>
    </form>

    <form method="POST" id="schluessel-weiter"
          action="{{ route('portal.wizard.schluessel.weiter', ['billingRun' => $billingRun->getKey()]) }}">
        @csrf
    </form>
@endsection
