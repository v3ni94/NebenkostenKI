@extends('layouts.portal')

@section('titel', 'Vorauszahlungen')

@section('content')
    <x-hvm.section-heading
        title="Schritt 7: Vorauszahlungen"
        lead="Abgezogen werden ausschließlich die tatsächlich geleisteten Vorauszahlungen. Die Sollsumme dient der Plausibilisierung." />

    <div class="mt-6">
        @include('portal.wizard.partials.fortschritt', [
            'fortschritt' => $fortschritt,
            'billingRun' => $billingRun,
            'wiedereinstieg' => $wiedereinstieg,
        ])
    </div>

    @if ($offen !== [])
        <x-hvm.alert variant="warning" class="mt-6" label="Fehlt noch"
                     title="Dieser Schritt ist Pflicht">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($offen as $grund)
                    <li>{{ $grund }}</li>
                @endforeach
            </ul>
        </x-hvm.alert>
    @endif

    <form method="POST" class="mt-6"
          action="{{ route('portal.wizard.vorauszahlungen.speichern', ['billingRun' => $billingRun->getKey()]) }}">
        @csrf

        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <caption class="sr-only">Vorauszahlungen je Mietverhältnis</caption>
                <thead>
                    <tr class="border-b border-hvm-mittelgrau text-left">
                        <th scope="col" class="p-2">Einheit und Mietverhältnis</th>
                        <th scope="col" class="p-2">Nutzungszeitraum</th>
                        <th scope="col" class="p-2">Monatlich Betriebskosten</th>
                        <th scope="col" class="p-2">Monatlich Heizkosten</th>
                        <th scope="col" class="p-2">Sollsumme</th>
                        <th scope="col" class="p-2">Tatsächlich geleistet</th>
                        <th scope="col" class="p-2">Herkunft</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($zeilen as $zeile)
                        <tr class="border-b border-hvm-umrissgrau align-top">
                            <td class="p-2">
                                <span class="font-semibold">{{ $zeile->unitLabel }}</span><br>
                                {{ $zeile->tenantLabel }}
                            </td>
                            <td class="p-2">
                                {{ \Illuminate\Support\Carbon::parse($zeile->usagePeriod->startIso())->format('d.m.Y') }}
                                bis
                                {{ \Illuminate\Support\Carbon::parse($zeile->usagePeriod->endIso())->format('d.m.Y') }}<br>
                                <span class="text-hvm-anthrazit">{{ $zeile->usageDays() }} Tage</span>
                            </td>
                            <td class="p-2">
                                {{ $zeile->monthlyOperating !== null ? $zeile->monthlyOperating->format() : 'nicht hinterlegt' }}
                            </td>
                            <td class="p-2">
                                @if ($zeile->heatingSeparate && $zeile->monthlyHeating !== null)
                                    {{ $zeile->monthlyHeating->format() }}
                                @else
                                    nicht getrennt vereinbart
                                @endif
                            </td>
                            <td class="p-2">
                                {{ $zeile->targetTotal->format() }}
                                <span class="mt-1 block text-hvm-anthrazit">{{ $zeile->targetExplanation }}</span>
                            </td>
                            <td class="p-2">
                                <label class="block">
                                    <span class="sr-only">Tatsächlich geleistete Vorauszahlungen für {{ $zeile->tenantLabel }}</span>
                                    <input type="text" inputmode="decimal"
                                           name="zeilen[{{ $zeile->tenancyId }}][ist]"
                                           value="{{ $zeile->actualTotal !== null ? $zeile->actualTotal->formatAmount() : '' }}"
                                           class="w-28 rounded border border-hvm-mittelgrau p-2"
                                           placeholder="0,00">
                                </label>

                                <label class="mt-2 flex items-start gap-2">
                                    <input type="checkbox" value="1" class="mt-1"
                                           name="zeilen[{{ $zeile->tenancyId }}][annahme]"
                                           @checked($zeile->assumedFromTarget)>
                                    <span>
                                        Ich habe keine Ist-Daten und bestätige ausdrücklich die Annahme
                                        Ist gleich Soll. Diese Annahme wird protokolliert und in der Abrechnung
                                        gekennzeichnet.
                                    </span>
                                </label>

                                @if ($zeile->hasDeviation())
                                    <span class="mt-2 block text-status-warning">
                                        Abweichung gegenüber der Sollsumme: {{ $zeile->deviation()->format() }}
                                    </span>
                                @endif

                                @if ($zeile->isOpen())
                                    <span class="mt-2 block text-status-error">
                                        Fehlt noch: Bitte tragen Sie den Betrag ein oder bestätigen Sie die Annahme.
                                    </span>
                                @endif
                            </td>
                            <td class="p-2">
                                <label class="block">
                                    <span class="sr-only">Herkunft für {{ $zeile->tenantLabel }}</span>
                                    <select name="zeilen[{{ $zeile->tenancyId }}][herkunft]"
                                            class="rounded border border-hvm-mittelgrau p-2">
                                        @foreach ($herkuenfte as $herkunft)
                                            <option value="{{ $herkunft->value }}"
                                                    @selected($herkunft->label() === $zeile->sourceLabel)>
                                                {{ $herkunft->label() }}
                                            </option>
                                        @endforeach
                                    </select>
                                </label>
                                <span class="mt-1 block text-hvm-anthrazit">Bisher: {{ $zeile->sourceLabel }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-6 flex flex-wrap gap-3">
            <x-hvm.button type="submit" variant="primary">Vorauszahlungen speichern</x-hvm.button>
        </div>
    </form>

    <form method="POST" class="mt-3"
          action="{{ route('portal.wizard.vorauszahlungen.weiter', ['billingRun' => $billingRun->getKey()]) }}">
        @csrf
        <x-hvm.button type="submit" variant="secondary">Weiter zu den Verteilerschlüsseln</x-hvm.button>
    </form>
@endsection
