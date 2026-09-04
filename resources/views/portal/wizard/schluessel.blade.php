@extends('layouts.portal')

@section('titel', 'Verteilerschlüssel und Verbrauch')

@section('content')
    <x-hvm.section-heading
        title="Schritt 8: Verteilerschlüssel und Verbrauch"
        lead="Je Kostenart legen Sie den Schlüssel fest. Wir zeigen Werte je Einheit, Nenner und Rechenweg." />

    <div class="mt-6">
        @include('portal.wizard.partials.fortschritt', [
            'fortschritt' => $fortschritt,
            'billingRun' => $billingRun,
            'wiedereinstieg' => $wiedereinstieg,
        ])
    </div>

    <x-hvm.alert variant="info" class="mt-6" label="Hinweis">
        <p>
            Der WEG-Schlüssel und der mietvertragliche Umlageschlüssel sind nicht dasselbe. Wir setzen beide nicht
            gleich. Bitte prüfen Sie die Mietvertragsregelung.
        </p>
    </x-hvm.alert>

    @if ($blocker !== [])
        <x-hvm.alert variant="error" class="mt-6" label="Blockiert die Abrechnung">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($blocker as $grund)
                    <li>{{ $grund }}</li>
                @endforeach
            </ul>
        </x-hvm.alert>
    @endif

    @if ($warnungen !== [])
        <x-hvm.alert variant="warning" class="mt-6" label="Bitte prüfen">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($warnungen as $warnung)
                    <li>{{ $warnung }}</li>
                @endforeach
            </ul>
        </x-hvm.alert>
    @endif

    @if ($ersatzverteilung !== [])
        <x-hvm.card class="mt-6" title="Verbrauch ohne Zwischenablesung">
            <p>
                Für die folgenden Einheiten liegt bei einem Nutzerwechsel keine Zwischenablesung vor. Wir schätzen
                nicht still. Sie können eine taggenaue Ersatzverteilung ausdrücklich bestätigen; sie wird in der
                Abrechnung gekennzeichnet.
            </p>

            <ul class="mt-3 space-y-3">
                @foreach ($ersatzverteilung as $einheit)
                    <li class="flex flex-wrap items-center gap-3">
                        <span>{{ $einheit->label }}</span>
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

    <form method="POST" class="mt-6"
          action="{{ route('portal.wizard.schluessel.speichern', ['billingRun' => $billingRun->getKey()]) }}">
        @csrf

        @foreach ($zeilen as $zeile)
            <x-hvm.card class="mt-4" :title="$zeile->categoryLabel">
                <div class="flex flex-wrap items-center gap-3">
                    <x-hvm.badge variant="info">Quelle: {{ $zeile->sourceBadge() }}</x-hvm.badge>
                    <x-hvm.badge variant="neutral">
                        Summe der Anteile: {{ $zeile->sharePercent }} Prozent
                    </x-hvm.badge>
                    <x-hvm.badge variant="neutral">Nenner: {{ $zeile->denominator }}</x-hvm.badge>
                </div>

                @if ($zeile->defaultWarning !== null)
                    <p class="mt-3 text-status-warning">{{ $zeile->defaultWarning }}</p>
                @endif

                @if ($zeile->deviationWarning() !== null)
                    <p class="mt-3 text-status-warning">{{ $zeile->deviationWarning() }}</p>
                @endif

                @if ($zeile->shareWarning() !== null)
                    <p class="mt-3 text-status-error">{{ $zeile->shareWarning() }}</p>
                @endif

                <label class="mt-4 block">
                    <span class="text-sm font-semibold">Verteilerschlüssel</span>
                    <select name="kostenarten[{{ $zeile->categoryId }}][key_type]"
                            class="mt-1 block rounded border border-hvm-mittelgrau p-2">
                        @foreach ($schluesseltypen as $typ)
                            <option value="{{ $typ->value }}" @selected($typ === $zeile->keyType)>
                                {{ $typ->label() }}
                            </option>
                        @endforeach
                    </select>
                </label>

                @php
                    $direktzuordnung = $zeile->keyType === \App\Enums\AllocationKeyType::DIREKT;
                    $wertspalte = $direktzuordnung ? 'Betrag in EUR' : 'Zähler';
                @endphp

                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    @unless ($direktzuordnung)
                        <label class="block">
                            <span class="text-sm font-semibold">Nenner, optional abweichend</span>
                            <input type="text" inputmode="decimal"
                                   name="kostenarten[{{ $zeile->categoryId }}][nenner]"
                                   value="{{ $zeile->denominator }}"
                                   class="mt-1 block w-full rounded border border-hvm-mittelgrau p-2">
                        </label>
                    @endunless

                    <label class="block">
                        <span class="text-sm font-semibold">Maßeinheit, optional</span>
                        <input type="text" name="kostenarten[{{ $zeile->categoryId }}][masseinheit]"
                               value="{{ $zeile->measurementUnit }}"
                               class="mt-1 block w-full rounded border border-hvm-mittelgrau p-2">
                    </label>
                </div>

                @if ($direktzuordnung)
                    <p class="mt-3 text-sm text-hvm-anthrazit">
                        Bei der Direktzuordnung tragen Sie je Mietverhältnis den Betrag in Euro ein, zum Beispiel
                        300,50. Die Summe der Beträge darf den Positionsbetrag der Kostenart nicht übersteigen; ein
                        nicht zugeordneter Rest verbleibt beim Eigentümer. Der Nenner ergibt sich aus dem
                        Positionsbetrag und wird nicht eingegeben.
                    </p>
                @endif

                <div class="mt-4 overflow-x-auto">
                    <table class="w-full border-collapse text-sm">
                        <caption class="sr-only">Werte je {{ $zeile->isUnitScope ? 'Einheit' : 'Mietverhältnis' }}</caption>
                        <thead>
                            <tr class="border-b border-hvm-mittelgrau text-left">
                                <th scope="col" class="p-2">{{ $zeile->isUnitScope ? 'Einheit' : 'Mietverhältnis' }}</th>
                                <th scope="col" class="p-2">{{ $wertspalte }}</th>
                                <th scope="col" class="p-2">Herkunft</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($zeile->values as $wert)
                                <tr class="border-b border-hvm-umrissgrau">
                                    <td class="p-2">{{ $wert->label }}</td>
                                    <td class="p-2">
                                        <label class="block">
                                            <span class="sr-only">{{ $wertspalte }} für {{ $wert->label }}</span>
                                            <input type="text" inputmode="decimal"
                                                   name="kostenarten[{{ $zeile->categoryId }}][werte][{{ $wert->participantId }}]"
                                                   value="{{ $wert->value }}"
                                                   @class([
                                                       'w-28 rounded border p-2',
                                                       'border-status-error' => $wert->isMissing(),
                                                       'border-hvm-mittelgrau' => ! $wert->isMissing(),
                                                   ])>
                                        </label>
                                        @if ($wert->isMissing())
                                            <span class="mt-1 block text-status-error">{{ $wert->missingText() }}</span>
                                        @endif
                                    </td>
                                    <td class="p-2 text-hvm-anthrazit">{{ $wert->herkunft ?? 'nicht angegeben' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-hvm.card>
        @endforeach

        <div class="mt-6">
            <x-hvm.button type="submit" variant="primary">Verteilerschlüssel speichern</x-hvm.button>
        </div>
    </form>

    <form method="POST" class="mt-3"
          action="{{ route('portal.wizard.schluessel.weiter', ['billingRun' => $billingRun->getKey()]) }}">
        @csrf
        <x-hvm.button type="submit" variant="secondary">Weiter zum Prüfbericht</x-hvm.button>
    </form>
@endsection
