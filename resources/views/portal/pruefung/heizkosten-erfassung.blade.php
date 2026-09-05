{{--
    Manuelle Heizkostenerfassung, Fall B (Zentralheizung ohne externen
    Abrechner).

    Die Plattform uebernimmt die eingetragenen Betraege unveraendert. Sie
    rechnet sie nicht nach und verteilt sie nicht selbst. Der Hinweistext ist
    allgemein gehalten und ausdruecklich keine Rechtsberatung im Einzelfall.
--}}
@extends('layouts.portal')

@section('titel', 'Heizkosten selbst erfassen')

@section('content')
    <x-hvm.page-header
        eyebrow="Heizkosten"
        title="Heizkosten selbst erfassen"
        lead="Tragen Sie hier die von Ihnen ermittelten Heizkosten je Einheit ein. Die Beträge werden unverändert übernommen."
        :back="route('portal.pruefung.heizkosten', ['billingRun' => $billingRun->getKey()])"
        backLabel="Zum Heizkostenabgleich" />

    <div class="mt-8 space-y-4">
        @if (session('status'))
            <x-hvm.alert variant="success">
                <p>{{ session('status') }}</p>
            </x-hvm.alert>
        @endif

        <x-hvm.alert variant="warning" title="Was die Plattform hier leistet und was nicht" data-heizkosten="hinweis-erfassung">
            <p>
                Die Plattform übernimmt die von Ihnen eingetragenen Beträge unverändert. Sie prüft und berechnet die
                Verteilung nach Grund- und Verbrauchskosten sowie die CO2-Kostenaufteilung nicht.
            </p>
            <p class="mt-2">
                Allgemeine Information: Die Heizkostenverordnung verlangt grundsätzlich eine überwiegend
                verbrauchsabhängige Abrechnung. Bei einer rein flächenbasierten Verteilung kann Mietern ein pauschales
                Kürzungsrecht zustehen. Wir empfehlen, einen Messdienstleister zu beauftragen. Das ist eine allgemeine
                Information und keine Rechtsberatung im Einzelfall.
            </p>
            <p class="mt-2">
                Verantwortlich für die Richtigkeit der eingetragenen Werte ist der Vermieter.
            </p>
        </x-hvm.alert>

        @if ($konflikte !== [])
            <x-hvm.alert variant="warning" title="Heizkosten aus mehreren Quellen" data-heizkosten="quellenkonflikt">
                <p>
                    Neben Ihrer Erfassung liegen weitere Heizkostenquellen vor: {{ implode('; ', $konflikte) }}. Die
                    Beträge werden nicht addiert. Bitte entscheiden Sie unten, welche Quelle gilt.
                </p>
            </x-hvm.alert>
        @endif

        @if (! $ergebnis->checksumAvailable && $ergebnis->hint !== null)
            <x-hvm.alert variant="info" data-heizkosten="ohne-gesamtbetrag">
                <p>{{ $ergebnis->hint }}</p>
            </x-hvm.alert>
        @endif

        @if ($ergebnis->blocksFinalization())
            <x-hvm.alert variant="warning" data-heizkosten="pruefsumme-blockiert">
                @foreach ($ergebnis->findings as $befund)
                    <p>{{ $befund->message }}</p>
                @endforeach
            </x-hvm.alert>
        @endif

        @if ($errors->any())
            <x-hvm.alert variant="warning">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $fehler)
                        <li>{{ $fehler }}</li>
                    @endforeach
                </ul>
            </x-hvm.alert>
        @endif
    </div>

    <form method="POST" action="{{ route('portal.pruefung.heizkosten.speichern', ['billingRun' => $billingRun->getKey()]) }}"
          class="mt-10 space-y-6">
        @csrf

        @if ($zeilen === [])
            <x-hvm.empty-state icon="house" title="Noch keine Einheit">
                <p>Es ist noch keine Einheit erfasst. Bitte legen Sie zuerst die Einheiten des Objekts an.</p>
            </x-hvm.empty-state>
        @else
            <div>
                <div class="overflow-hidden rounded-3xl border border-hvm-linie bg-white">
                    <table class="hvm-table hvm-table-stack text-base">
                        <caption class="sr-only">Selbst ermittelte Heizkosten je Einheit in Euro</caption>
                        <thead>
                            <tr>
                                <th scope="col">Einheit</th>
                                <th scope="col">Heizung</th>
                                <th scope="col">Warmwasser</th>
                                <th scope="col">CO2-Anteil Vermieter</th>
                                <th scope="col">CO2-Anteil Mieter</th>
                                <th scope="col">Sonstige Kosten des Heizbetriebs</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($zeilen as $zeile)
                                <tr>
                                    <th scope="row" class="font-medium sm:w-48">
                                        {{ $zeile->unitLabel }}
                                        @if ($zeile->hasTenantChange())
                                            <span class="mt-1 block text-sm leading-relaxed font-normal text-hvm-text-sekundaer" data-heizkosten="mieterwechsel">
                                                Mieterwechsel im Zeitraum. Der Betrag wird zeitanteilig nach
                                                Nutzungstagen verteilt:
                                                @foreach ($zeile->occupancies as $nutzung)
                                                    {{ $nutzung->label }} ({{ $nutzung->periodLabel }}, {{ $nutzung->days }} Tage){{ ! $loop->last ? ', ' : '' }}
                                                @endforeach
                                            </span>
                                        @endif
                                    </th>
                                    @foreach ([
                                        'heizung' => ['Heizung', $zeile->heating],
                                        'warmwasser' => ['Warmwasser', $zeile->warmWater],
                                        'co2_vermieter' => ['CO2-Anteil Vermieter', $zeile->co2Landlord],
                                        'co2_mieter' => ['CO2-Anteil Mieter', $zeile->co2Tenant],
                                        'sonstige' => ['Sonstige Kosten des Heizbetriebs', $zeile->other],
                                    ] as $feld => [$spalte, $betrag])
                                        <td data-label="{{ $spalte }}">
                                            <label for="heizkosten-{{ $zeile->unitId }}-{{ $feld }}" class="sr-only">{{ $feld }} für {{ $zeile->unitLabel }}</label>
                                            <input type="text" inputmode="decimal" maxlength="20"
                                                   id="heizkosten-{{ $zeile->unitId }}-{{ $feld }}"
                                                   name="einheiten[{{ $zeile->unitId }}][{{ $feld }}]"
                                                   value="{{ old('einheiten.'.$zeile->unitId.'.'.$feld, $zeile->formValue($betrag)) }}"
                                                   placeholder="0,00"
                                                   class="hvm-input text-right tabular sm:min-w-28" />
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 max-w-prose text-sm leading-relaxed text-hvm-text-sekundaer">
                    Beträge in Euro, zum Beispiel 1.234,56. Der CO2-Kostenanteil des Vermieters wird nicht auf die
                    Mieter umgelegt und erscheint nur im internen Übersichtsblatt.
                </p>
            </div>
        @endif

        <x-hvm.card :kennlinie="true" padding="none" class="rounded-3xl">
            <div class="space-y-6 p-6 sm:p-8">
                <h2 class="text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl">Gegenprobe und Herkunft der Berechnung</h2>

                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <x-hvm.field name="gesamtbetrag" label="Gesamtbetrag der Heizkosten, optional" inputmode="decimal" maxlength="20"
                                 placeholder="0,00" :value="old('gesamtbetrag', $gesamtbetrag)" class="text-right tabular"
                                 hint="Ist ein Gesamtbetrag erfasst, wird die Summe der Einzelbeträge dagegen geprüft. Ohne Gesamtbetrag ist keine Gegenprobe möglich." />

                    <x-hvm.field name="herkunft" label="Herkunft der Berechnung" type="textarea" rows="3" maxlength="2000"
                                 :value="old('herkunft', $herkunft)"
                                 hint="Zum Beispiel eigene Tabellenkalkulation vom 15.03.2026, Grundkostenanteil 30 Prozent. Diese Angabe erscheint ausschließlich im internen Übersichtsblatt für den Eigentümer." />
                </div>

                @if ($konflikte !== [])
                    <x-hvm.field name="quelle" label="Welche Quelle gilt?" type="radio-group" :value="$quelle"
                                 :options="[
                                     'MANUELL' => 'Es gelten die hier erfassten Beträge.',
                                     'EXTERN' => 'Es gilt die externe Abrechnung beziehungsweise die Position der Hausgeldabrechnung.',
                                 ]" />
                @endif

                @if ($ergebnis->checksumAvailable)
                    <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div class="min-w-0 rounded-2xl bg-hvm-canvas p-4">
                            <dt class="text-sm leading-5 font-semibold text-hvm-text-sekundaer">Erfasster Gesamtbetrag</dt>
                            <dd class="mt-1 text-xl font-semibold tracking-tight text-hvm-textschwarz tabular whitespace-nowrap">{{ $ergebnis->declaredTotal?->format() }}</dd>
                        </div>
                        <div class="min-w-0 rounded-2xl bg-hvm-canvas p-4">
                            <dt class="text-sm leading-5 font-semibold text-hvm-text-sekundaer">Summe der erfassten Beträge</dt>
                            <dd class="mt-1 text-xl font-semibold tracking-tight text-hvm-textschwarz tabular whitespace-nowrap">{{ $ergebnis->sumOfRecordedAmounts->format() }}</dd>
                        </div>
                        <div class="min-w-0 rounded-2xl bg-hvm-canvas p-4">
                            <dt class="text-sm leading-5 font-semibold text-hvm-text-sekundaer">Abweichung, zulässig {{ $ergebnis->tolerance->format() }}</dt>
                            <dd class="mt-1 text-xl font-semibold tracking-tight text-hvm-textschwarz tabular whitespace-nowrap">{{ $ergebnis->difference?->format() }}</dd>
                        </div>
                    </dl>
                @endif

                <div class="flex flex-wrap gap-3">
                    <x-hvm.button type="submit">Beträge speichern</x-hvm.button>
                    <x-hvm.button href="{{ route('portal.pruefung.heizkosten', ['billingRun' => $billingRun->getKey()]) }}"
                                  variant="secondary">Zum Heizkostenabgleich</x-hvm.button>
                </div>
            </div>
        </x-hvm.card>
    </form>
@endsection
