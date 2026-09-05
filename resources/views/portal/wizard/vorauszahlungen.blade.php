{{--
    Schritt 7: Vorauszahlungen je Mietverhaeltnis.

    Darstellung als Liste von Karten (Muster "Eintraege mit viel Inhalt"):
    links die Vertragsdaten und die Sollsumme, rechts die Eingaben. Eine
    Tabelle mit sieben Spalten und Eingabefeldern waere auf Desktop kaum und
    auf Mobil gar nicht bedienbar. Alle Spaltenbegriffe bleiben als
    Beschriftungen erhalten.
--}}
@extends('layouts.portal')

@section('titel', 'Vorauszahlungen')

@section('content')
    <x-hvm.page-header
        :eyebrow="$schritt->eyebrow()"
        title="Vorauszahlungen"
        lead="Abgezogen werden ausschließlich die tatsächlich geleisteten Vorauszahlungen. Die Sollsumme dient der Plausibilisierung." />

    <div class="mt-8">
        @include('portal.wizard.partials.fortschritt', [
            'fortschritt' => $fortschritt,
            'billingRun' => $billingRun,
            'wiedereinstieg' => $wiedereinstieg,
        ])
    </div>

    @if ($offen !== [])
        {{-- Kategorie "Fehlt noch" = Variante info (Statuszuordnung 4.9). --}}
        <x-hvm.alert variant="info" class="mt-8" label="Fehlt noch"
                     title="Dieser Schritt ist Pflicht">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($offen as $grund)
                    <li>{{ $grund }}</li>
                @endforeach
            </ul>
        </x-hvm.alert>
    @endif

    <form method="POST" class="mt-10"
          action="{{ route('portal.wizard.vorauszahlungen.speichern', ['billingRun' => $billingRun->getKey()]) }}">
        @csrf

        <h2 class="sr-only">Vorauszahlungen je Mietverhältnis</h2>

        <div class="space-y-4">
            @foreach ($zeilen as $zeile)
                @php
                    $feldId = 'vorauszahlung-'.$zeile->tenancyId;
                @endphp
                <x-hvm.card padding="none">
                    <x-hvm.list-row :stacked="true" :title="$zeile->unitLabel" :subtitle="$zeile->tenantLabel">
                        <x-slot:actions>
                            @if ($zeile->isOpen())
                                <x-hvm.badge variant="info">Fehlt noch</x-hvm.badge>
                            @elseif ($zeile->hasDeviation())
                                <x-hvm.badge variant="warning">Bitte prüfen</x-hvm.badge>
                            @else
                                <x-hvm.badge variant="success">Erledigt</x-hvm.badge>
                            @endif
                        </x-slot:actions>

                        <div class="grid grid-cols-1 gap-6 border-t border-hvm-linie pt-5 lg:grid-cols-12 lg:gap-8">
                            {{-- Vertragsdaten und Sollsumme ------------------------------------ --}}
                            <dl class="grid min-w-0 grid-cols-1 gap-4 text-sm sm:grid-cols-2 lg:col-span-5 lg:grid-cols-1">
                                <div class="min-w-0">
                                    <dt class="text-xs font-semibold tracking-[0.08em] text-hvm-text-sekundaer uppercase">Einheit und Mietverhältnis</dt>
                                    <dd class="mt-1 text-hvm-textschwarz">{{ $zeile->unitLabel }}, {{ $zeile->tenantLabel }}</dd>
                                </div>
                                <div class="min-w-0">
                                    <dt class="text-xs font-semibold tracking-[0.08em] text-hvm-text-sekundaer uppercase">Nutzungszeitraum</dt>
                                    <dd class="mt-1 text-hvm-textschwarz">
                                        {{ \Illuminate\Support\Carbon::parse($zeile->usagePeriod->startIso())->format('d.m.Y') }}
                                        bis
                                        {{ \Illuminate\Support\Carbon::parse($zeile->usagePeriod->endIso())->format('d.m.Y') }}
                                        <span class="block text-hvm-text-sekundaer">{{ $zeile->usageDays() }} Tage</span>
                                    </dd>
                                </div>
                                <div class="min-w-0">
                                    <dt class="text-xs font-semibold tracking-[0.08em] text-hvm-text-sekundaer uppercase">Monatlich Betriebskosten</dt>
                                    <dd class="mt-1 text-hvm-textschwarz tabular">
                                        {{ $zeile->monthlyOperating !== null ? $zeile->monthlyOperating->format() : 'nicht hinterlegt' }}
                                    </dd>
                                </div>
                                <div class="min-w-0">
                                    <dt class="text-xs font-semibold tracking-[0.08em] text-hvm-text-sekundaer uppercase">Monatlich Heizkosten</dt>
                                    <dd class="mt-1 text-hvm-textschwarz tabular">
                                        @if ($zeile->heatingSeparate && $zeile->monthlyHeating !== null)
                                            {{ $zeile->monthlyHeating->format() }}
                                        @else
                                            nicht getrennt vereinbart
                                        @endif
                                    </dd>
                                </div>
                                <div class="min-w-0 rounded-2xl bg-hvm-canvas p-4 sm:col-span-2 lg:col-span-1">
                                    <dt class="text-xs font-semibold tracking-[0.08em] text-hvm-text-sekundaer uppercase">Sollsumme</dt>
                                    <dd class="mt-1">
                                        <span class="text-xl font-semibold tracking-tight text-hvm-textschwarz tabular whitespace-nowrap">{{ $zeile->targetTotal->format() }}</span>
                                        <span class="mt-1 block text-sm leading-relaxed text-hvm-text-sekundaer">{{ $zeile->targetExplanation }}</span>
                                    </dd>
                                </div>
                            </dl>

                            {{-- Eingaben ------------------------------------------------------- --}}
                            <div class="min-w-0 space-y-6 lg:col-span-7">
                                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                    <div class="min-w-0">
                                        <label for="{{ $feldId }}-ist" class="block text-sm font-semibold text-hvm-textschwarz">
                                            Tatsächlich geleistet<span class="sr-only">e Vorauszahlungen für {{ $zeile->tenantLabel }}</span>
                                        </label>
                                        <div class="mt-2">
                                            <input type="text" inputmode="decimal"
                                                   id="{{ $feldId }}-ist"
                                                   name="zeilen[{{ $zeile->tenancyId }}][ist]"
                                                   value="{{ $zeile->actualTotal !== null ? $zeile->actualTotal->formatAmount() : '' }}"
                                                   class="hvm-input text-right tabular"
                                                   @if ($zeile->isOpen()) aria-describedby="{{ $feldId }}-hinweis" @endif
                                                   @if ($errors->has('zeilen.'.$zeile->tenancyId.'.ist')) aria-invalid="true" @endif
                                                   placeholder="0,00">
                                        </div>
                                    </div>

                                    <div class="min-w-0">
                                        <label for="{{ $feldId }}-herkunft" class="block text-sm font-semibold text-hvm-textschwarz">
                                            Herkunft<span class="sr-only"> für {{ $zeile->tenantLabel }}</span>
                                        </label>
                                        <div class="mt-2">
                                            <select id="{{ $feldId }}-herkunft" name="zeilen[{{ $zeile->tenancyId }}][herkunft]" class="hvm-input">
                                                @foreach ($herkuenfte as $herkunft)
                                                    <option value="{{ $herkunft->value }}"
                                                            @selected($herkunft->label() === $zeile->sourceLabel)>
                                                        {{ $herkunft->label() }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <p class="mt-2 text-sm text-hvm-text-sekundaer">Bisher: {{ $zeile->sourceLabel }}</p>
                                    </div>
                                </div>

                                <x-hvm.field
                                    :name="'zeilen['.$zeile->tenancyId.'][annahme]'"
                                    :id="$feldId.'-annahme'"
                                    type="checkbox"
                                    value="1"
                                    align="start"
                                    :errors="false"
                                    :checked="$zeile->assumedFromTarget"
                                    label="Ich habe keine Ist-Daten und bestätige ausdrücklich die Annahme Ist gleich Soll. Diese Annahme wird protokolliert und in der Abrechnung gekennzeichnet." />

                                @if ($zeile->hasDeviation())
                                    <p class="flex items-start gap-1.5 text-sm font-medium text-status-warning">
                                        <x-hvm.icon name="warning" class="mt-0.5 h-4 w-4" />
                                        <span>Abweichung gegenüber der Sollsumme: <span class="tabular whitespace-nowrap">{{ $zeile->deviation()->format() }}</span></span>
                                    </p>
                                @endif

                                {{-- "Fehlt noch" ist die Info-Kategorie (inbox), kein Fehler: rot bleibt den Blockern vorbehalten. --}}
                                @if ($zeile->isOpen())
                                    <p id="{{ $feldId }}-hinweis" class="flex items-start gap-1.5 text-sm font-medium text-status-info">
                                        <x-hvm.icon :name="\App\Support\Statussymbol::INFO" class="mt-0.5 h-4 w-4" />
                                        <span>Fehlt noch: Bitte tragen Sie den Betrag ein oder bestätigen Sie die Annahme.</span>
                                    </p>
                                @endif
                            </div>
                        </div>
                    </x-hvm.list-row>
                </x-hvm.card>
            @endforeach
        </div>

        {{-- Buttonreihe (4.12): Speichern und Weiter nebeneinander, das zweite Formular ist Flex-Kind. --}}
        <div class="mt-8 flex flex-wrap gap-3">
            <x-hvm.button type="submit" variant="primary">Vorauszahlungen speichern</x-hvm.button>
            <x-hvm.button type="submit" variant="secondary" form="vorauszahlungen-weiter">
                Weiter zu den Verteilerschlüsseln
                <x-hvm.icon name="arrow-right" class="h-4 w-4" />
            </x-hvm.button>
        </div>
    </form>

    <form method="POST" id="vorauszahlungen-weiter"
          action="{{ route('portal.wizard.vorauszahlungen.weiter', ['billingRun' => $billingRun->getKey()]) }}">
        @csrf
    </form>
@endsection
