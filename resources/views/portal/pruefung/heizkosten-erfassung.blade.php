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
    <x-hvm.section-heading
        title="Heizkosten selbst erfassen"
        lead="Tragen Sie hier die von Ihnen ermittelten Heizkosten je Einheit ein. Die Beträge werden unverändert übernommen." />

    @if (session('status'))
        <x-hvm.alert variant="success" class="mt-6">
            <p>{{ session('status') }}</p>
        </x-hvm.alert>
    @endif

    <x-hvm.alert variant="warning" class="mt-6" data-heizkosten="hinweis-erfassung">
        <p class="font-semibold">Was die Plattform hier leistet und was nicht</p>
        <p class="mt-2">
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
        <x-hvm.alert variant="warning" class="mt-4" data-heizkosten="quellenkonflikt">
            <p class="font-semibold">Heizkosten aus mehreren Quellen</p>
            <p class="mt-2">
                Neben Ihrer Erfassung liegen weitere Heizkostenquellen vor: {{ implode('; ', $konflikte) }}. Die
                Beträge werden nicht addiert. Bitte entscheiden Sie unten, welche Quelle gilt.
            </p>
        </x-hvm.alert>
    @endif

    @if (! $ergebnis->checksumAvailable && $ergebnis->hint !== null)
        <x-hvm.alert variant="info" class="mt-4" data-heizkosten="ohne-gesamtbetrag">
            <p>{{ $ergebnis->hint }}</p>
        </x-hvm.alert>
    @endif

    @if ($ergebnis->blocksFinalization())
        <x-hvm.alert variant="warning" class="mt-4" data-heizkosten="pruefsumme-blockiert">
            @foreach ($ergebnis->findings as $befund)
                <p>{{ $befund->message }}</p>
            @endforeach
        </x-hvm.alert>
    @endif

    @if ($errors->any())
        <x-hvm.alert variant="warning" class="mt-4">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $fehler)
                    <li>{{ $fehler }}</li>
                @endforeach
            </ul>
        </x-hvm.alert>
    @endif

    <form method="POST" action="{{ route('portal.pruefung.heizkosten.speichern', ['billingRun' => $billingRun->getKey()]) }}"
          class="mt-6">
        @csrf

        <x-hvm.card>
            @if ($zeilen === [])
                <p>Es ist noch keine Einheit erfasst. Bitte legen Sie zuerst die Einheiten des Objekts an.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[52rem] border-collapse text-sm">
                        <caption class="sr-only">Selbst ermittelte Heizkosten je Einheit in Euro</caption>
                        <thead>
                            <tr class="border-b border-hvm-mittelgrau text-left">
                                <th scope="col" class="py-2 pr-4">Einheit</th>
                                <th scope="col" class="py-2 pr-4">Heizung</th>
                                <th scope="col" class="py-2 pr-4">Warmwasser</th>
                                <th scope="col" class="py-2 pr-4">CO2-Anteil Vermieter</th>
                                <th scope="col" class="py-2 pr-4">CO2-Anteil Mieter</th>
                                <th scope="col" class="py-2">Sonstige Kosten des Heizbetriebs</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($zeilen as $zeile)
                                <tr class="border-b border-hvm-hellgrau align-top">
                                    <td class="py-2 pr-4">
                                        <span class="font-semibold">{{ $zeile->unitLabel }}</span>
                                        @if ($zeile->hasTenantChange())
                                            <br />
                                            <span class="text-xs" data-heizkosten="mieterwechsel">
                                                Mieterwechsel im Zeitraum. Der Betrag wird zeitanteilig nach
                                                Nutzungstagen verteilt:
                                                @foreach ($zeile->occupancies as $nutzung)
                                                    {{ $nutzung->label }} ({{ $nutzung->periodLabel }}, {{ $nutzung->days }} Tage){{ ! $loop->last ? ', ' : '' }}
                                                @endforeach
                                            </span>
                                        @endif
                                    </td>
                                    @foreach ([
                                        'heizung' => $zeile->heating,
                                        'warmwasser' => $zeile->warmWater,
                                        'co2_vermieter' => $zeile->co2Landlord,
                                        'co2_mieter' => $zeile->co2Tenant,
                                        'sonstige' => $zeile->other,
                                    ] as $feld => $betrag)
                                        <td class="py-2 pr-4">
                                            <label class="block">
                                                <span class="sr-only">{{ $feld }} für {{ $zeile->unitLabel }}</span>
                                                <input type="text" inputmode="decimal" maxlength="20"
                                                       name="einheiten[{{ $zeile->unitId }}][{{ $feld }}]"
                                                       value="{{ old('einheiten.'.$zeile->unitId.'.'.$feld, $zeile->formValue($betrag)) }}"
                                                       placeholder="0,00"
                                                       class="mt-1 w-28 rounded-md border border-hvm-mittelgrau px-2 py-1 text-right" />
                                            </label>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 text-xs">
                    Beträge in Euro, zum Beispiel 1.234,56. Der CO2-Kostenanteil des Vermieters wird nicht auf die
                    Mieter umgelegt und erscheint nur im internen Übersichtsblatt.
                </p>
            @endif
        </x-hvm.card>

        <x-hvm.card class="mt-6" title="Gegenprobe und Herkunft der Berechnung">
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="text-sm font-semibold">Gesamtbetrag der Heizkosten, optional</span>
                    <input type="text" inputmode="decimal" maxlength="20" name="gesamtbetrag"
                           value="{{ old('gesamtbetrag', $gesamtbetrag) }}" placeholder="0,00"
                           class="mt-1 w-full rounded-md border border-hvm-mittelgrau px-3 py-2 text-right" />
                    <span class="mt-1 block text-xs">
                        Ist ein Gesamtbetrag erfasst, wird die Summe der Einzelbeträge dagegen geprüft. Ohne
                        Gesamtbetrag ist keine Gegenprobe möglich.
                    </span>
                </label>

                <label class="block">
                    <span class="text-sm font-semibold">Herkunft der Berechnung</span>
                    <textarea name="herkunft" rows="3" maxlength="2000"
                              class="mt-1 w-full rounded-md border border-hvm-mittelgrau px-3 py-2">{{ old('herkunft', $herkunft) }}</textarea>
                    <span class="mt-1 block text-xs">
                        Zum Beispiel eigene Tabellenkalkulation vom 15.03.2026, Grundkostenanteil 30 Prozent. Diese
                        Angabe erscheint ausschließlich im internen Übersichtsblatt für den Eigentümer.
                    </span>
                </label>
            </div>

            @if ($konflikte !== [])
                <fieldset class="mt-4">
                    <legend class="text-sm font-semibold">Welche Quelle gilt?</legend>
                    <label class="mt-2 flex items-center gap-2 text-sm">
                        <input type="radio" name="quelle" value="MANUELL" @checked($quelle === 'MANUELL') />
                        <span>Es gelten die hier erfassten Beträge.</span>
                    </label>
                    <label class="mt-2 flex items-center gap-2 text-sm">
                        <input type="radio" name="quelle" value="EXTERN" @checked($quelle === 'EXTERN') />
                        <span>Es gilt die externe Abrechnung beziehungsweise die Position der Hausgeldabrechnung.</span>
                    </label>
                </fieldset>
            @endif

            @if ($ergebnis->checksumAvailable)
                <dl class="mt-6 grid gap-3 sm:grid-cols-3">
                    <div>
                        <dt class="text-sm">Erfasster Gesamtbetrag</dt>
                        <dd class="font-semibold">{{ $ergebnis->declaredTotal?->format() }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm">Summe der erfassten Beträge</dt>
                        <dd class="font-semibold">{{ $ergebnis->sumOfRecordedAmounts->format() }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm">Abweichung, zulässig {{ $ergebnis->tolerance->format() }}</dt>
                        <dd class="font-semibold">{{ $ergebnis->difference?->format() }}</dd>
                    </div>
                </dl>
            @endif

            <div class="mt-6 flex flex-wrap gap-3">
                <x-hvm.button type="submit">Beträge speichern</x-hvm.button>
                <x-hvm.button href="{{ route('portal.pruefung.heizkosten', ['billingRun' => $billingRun->getKey()]) }}"
                              variant="secondary" size="sm">Zum Heizkostenabgleich</x-hvm.button>
            </div>
        </x-hvm.card>
    </form>
@endsection
