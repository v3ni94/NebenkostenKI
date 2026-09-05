@extends('layouts.site')

@section('meta_title', 'Preise')
@section('meta_description', 'Transparente Preise: ein Festpreis je erzeugter Mieterabrechnung, Konto und Entwürfe kostenlos, Zahlung erst nach der Vorschau, kein Abonnement.')

@php
    /*
    | Alle Beträge stammen aus der Konfiguration und werden hier nur formatiert.
    | Netto = Brutto / (1 + Satz / 100), kaufmännisch auf Cent gerundet.
    | Die Umsatzsteuer ergibt sich als Differenz, damit die Summe exakt bleibt.
    */
    $bruttoJeAbrechnungCent = (int) config('smartabrechnen.pricing.per_statement_gross_cent');
    $grundpreisCent = (int) config('smartabrechnen.pricing.base_gross_cent');
    $steuersatz = (int) config('smartabrechnen.pricing.vat_rate_percent');

    $euro = static fn (int $cent): string => number_format($cent / 100, 2, ',', '.').' EUR';
    $netto = static fn (int $bruttoCent): int => (int) round($bruttoCent / (1 + $steuersatz / 100));

    $nettoJeAbrechnungCent = $netto($bruttoJeAbrechnungCent);
    $steuerJeAbrechnungCent = $bruttoJeAbrechnungCent - $nettoJeAbrechnungCent;

    // Rechenbeispiel: 6 Einheiten, davon eine mit Mieterwechsel, also 7 Mieterabrechnungen.
    $beispielEinheiten = 6;
    $beispielWechsel = 1;
    $beispielAbrechnungen = $beispielEinheiten + $beispielWechsel;

    $beispielBruttoCent = $beispielAbrechnungen * $bruttoJeAbrechnungCent + $grundpreisCent;
    $beispielNettoCent = $netto($beispielBruttoCent);
    $beispielSteuerCent = $beispielBruttoCent - $beispielNettoCent;

    $operator = config('smartabrechnen.operator');
@endphp

@section('content')
    {{-- Seitenkopf mit Preisblock --}}
    <section class="bg-hvm-canvas">
        <div class="mx-auto max-w-7xl px-4 pt-16 pb-20 sm:px-6 lg:px-8 lg:pt-24 lg:pb-28">
            <div class="grid gap-12 lg:grid-cols-12 lg:items-start">
                <div class="lg:col-span-6">
                    <x-hvm.badge variant="akzent" :icon="false">Preise</x-hvm.badge>
                    {{-- Weiche Trennstelle im Kompositum, damit die H1 mobil und in der schmalen Spalte sauber bricht. --}}
                    <h1 class="mt-6 text-4xl leading-[1.05] font-semibold tracking-tight text-hvm-textschwarz sm:text-5xl lg:text-6xl">
                        Ein Festpreis je erzeugter Mieter&shy;abrechnung
                    </h1>
                    <p class="mt-7 max-w-prose text-lg leading-relaxed text-hvm-text-sekundaer sm:text-xl">
                        Das Konto und alle Entwürfe sind kostenlos. Bezahlt wird erst, wenn Sie die Vorschau geprüft haben und
                        die Abrechnungen ohne Wasserzeichen erhalten möchten. Es entsteht kein Abonnement.
                    </p>

                    <div class="mt-10 rounded-3xl border border-hvm-linie bg-white p-7 sm:p-9">
                        <p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">Immer enthalten</p>
                        <ul class="mt-5 space-y-3 text-base text-hvm-textschwarz">
                            <li class="flex gap-3"><span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="check" /></span><span>Konto, Objekte und Entwürfe kostenlos</span></li>
                            <li class="flex gap-3"><span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="check" /></span><span>Auswertung Ihrer Unterlagen und Prüfbericht</span></li>
                            <li class="flex gap-3"><span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="check" /></span><span>Vorschau aller Abrechnungen mit Wasserzeichen</span></li>
                            <li class="flex gap-3"><span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="check" /></span><span>Einzel-PDFs, ZIP-Datei und Eigentümerübersicht nach Zahlung</span></li>
                            <li class="flex gap-3"><span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="check" /></span><span>Übernahme der Daten in das Folgejahr</span></li>
                            <li class="flex gap-3"><span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="check" /></span><span>Erinnerungen für Folgejahre, jederzeit abschaltbar</span></li>
                        </ul>
                    </div>
                </div>

                <div class="lg:col-span-5 lg:col-start-8">
                    <div class="overflow-hidden rounded-3xl border border-hvm-linie bg-white">
                        <div class="hvm-kennlinie" aria-hidden="true"></div>
                        <div class="p-7 sm:p-9">
                            <p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">Je Mieterabrechnung</p>
                            <p class="mt-4 text-5xl font-semibold tracking-tight text-hvm-textschwarz tabular sm:text-6xl">{{ $euro($bruttoJeAbrechnungCent) }}</p>
                            <p class="mt-3 text-sm text-hvm-text-sekundaer">brutto, inklusive Umsatzsteuer</p>

                            <dl class="mt-8 space-y-3 border-t border-hvm-linie pt-6 text-base text-hvm-textschwarz tabular">
                                <div class="flex justify-between gap-4">
                                    <dt class="text-hvm-text-sekundaer">Netto</dt>
                                    <dd class="font-medium whitespace-nowrap">{{ $euro($nettoJeAbrechnungCent) }}</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-hvm-text-sekundaer">Umsatzsteuer {{ $steuersatz }} Prozent</dt>
                                    <dd class="font-medium whitespace-nowrap">{{ $euro($steuerJeAbrechnungCent) }}</dd>
                                </div>
                                <div class="flex justify-between gap-4 border-t border-hvm-linie pt-3">
                                    <dt class="font-semibold">Brutto</dt>
                                    <dd class="font-semibold whitespace-nowrap">{{ $euro($bruttoJeAbrechnungCent) }}</dd>
                                </div>
                            </dl>

                            <p class="mt-8 rounded-2xl bg-hvm-canvas p-4 text-base text-hvm-textschwarz">
                                Grundpreis je Abrechnungslauf:
                                <span class="font-semibold whitespace-nowrap tabular">{{ $euro($grundpreisCent) }}</span>
                                @if ($grundpreisCent === 0)
                                    <span class="mt-1 block text-sm text-hvm-text-sekundaer">Es fällt derzeit kein Grundpreis an.</span>
                                @endif
                            </p>

                            <div class="mt-8">
                                <x-hvm.button href="{{ url('/app') }}" variant="primary" size="lg" class="w-full">Kostenlos starten</x-hvm.button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Abrechnungseinheit --}}
    <section class="border-y border-hvm-linie bg-white">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <div class="grid gap-12 lg:grid-cols-12">
                <div class="lg:col-span-5">
                    <x-hvm.section-heading
                        level="h2"
                        eyebrow="Abrechnungseinheit"
                        title="Gezählt wird die erzeugte Mieterabrechnung"
                        lead="Nicht die Wohnung ist die Abrechnungseinheit für den Preis, sondern die einzelne Mieterabrechnung. Wechselt der Mieter im Abrechnungszeitraum, entstehen für dieselbe Einheit mehrere Abrechnungen." />
                </div>

                <div class="lg:col-span-7">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-hvm.card title="Eine Einheit, ein Mietverhältnis" tone="canvas">
                            <p class="mb-4 text-4xl font-semibold tracking-tight text-hvm-textschwarz tabular" aria-hidden="true">1</p>
                            <p class="text-hvm-text-sekundaer">Eine Wohnung, durchgehend an dieselben Mieter vermietet, ergibt eine Mieterabrechnung.</p>
                        </x-hvm.card>

                        <x-hvm.card title="Eine Einheit, ein Mieterwechsel" tone="canvas">
                            <p class="mb-4 text-4xl font-semibold tracking-tight text-hvm-textschwarz tabular" aria-hidden="true">2</p>
                            <p class="text-hvm-text-sekundaer">
                                Zieht ein Mieter aus und ein neuer ein, werden zwei Nutzungszeiträume abgerechnet und damit zwei
                                Mieterabrechnungen erzeugt.
                            </p>
                        </x-hvm.card>
                    </div>

                    <div class="mt-5">
                        <x-hvm.alert variant="info" title="Leerstand">
                            Für eine leerstehende Einheit entsteht keine Mieterabrechnung und damit kein Preis. Die auf den
                            Leerstand entfallenden Kosten bleiben beim Eigentümer und erscheinen in der Eigentümerübersicht.
                        </x-hvm.alert>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Rechenbeispiel --}}
    <section class="bg-hvm-canvas">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <x-hvm.section-heading
                level="h2"
                eyebrow="Rechenbeispiel"
                title="{{ $beispielEinheiten }} Einheiten mit einem Mieterwechsel" />

            <p class="mt-5 max-w-prose text-base leading-relaxed text-hvm-text-sekundaer sm:text-lg">
                Ein Mehrfamilienhaus mit {{ $beispielEinheiten }} vermieteten Einheiten. In einer Einheit wechselt der
                Mieter im Abrechnungsjahr. Daraus ergeben sich {{ $beispielAbrechnungen }} Mieterabrechnungen.
            </p>

            {{--
                Rechenbeispiel: ab sm eine klassische Tabelle, unter sm gestapelt
                (.hvm-table-stack): jede Zeile ein Block, Spaltenbeschriftung aus
                data-label, kein horizontales Scrollen, Betraege ohne Umbruch.
            --}}
            <div class="mt-12 overflow-hidden rounded-3xl border border-hvm-linie bg-white">
                <table class="hvm-table hvm-table-zebra hvm-table-stack text-base">
                    <caption class="sr-only">
                        Rechenbeispiel für {{ $beispielAbrechnungen }} Mieterabrechnungen
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">Position</th>
                            <th scope="col">Rechenweg</th>
                            <th scope="col" class="betrag">Betrag</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <th scope="row" class="font-medium">Mieterabrechnungen</th>
                            <td class="text-hvm-text-sekundaer" data-label="Rechenweg">
                                {{ $beispielEinheiten }} Einheiten plus {{ $beispielWechsel }} Mieterwechsel
                            </td>
                            <td class="betrag" data-label="Betrag">{{ $beispielAbrechnungen }} Stück</td>
                        </tr>
                        <tr>
                            <th scope="row" class="font-medium">Preis je Abrechnung</th>
                            <td class="text-hvm-text-sekundaer" data-label="Rechenweg">Festpreis brutto</td>
                            <td class="betrag" data-label="Betrag">{{ $euro($bruttoJeAbrechnungCent) }}</td>
                        </tr>
                        <tr>
                            <th scope="row" class="font-medium">Grundpreis</th>
                            <td class="text-hvm-text-sekundaer" data-label="Rechenweg">je Abrechnungslauf</td>
                            <td class="betrag" data-label="Betrag">{{ $euro($grundpreisCent) }}</td>
                        </tr>
                        <tr>
                            <th scope="row" class="font-semibold">Summe brutto</th>
                            <td class="text-hvm-text-sekundaer" data-label="Rechenweg">
                                {{ $beispielAbrechnungen }} mal {{ $euro($bruttoJeAbrechnungCent) }} plus {{ $euro($grundpreisCent) }}
                            </td>
                            <td class="betrag text-lg font-semibold" data-label="Betrag">{{ $euro($beispielBruttoCent) }}</td>
                        </tr>
                        <tr>
                            <th scope="row" class="font-medium">davon netto</th>
                            <td class="text-hvm-text-sekundaer" data-label="Rechenweg">
                                {{ $euro($beispielBruttoCent) }} geteilt durch 1,{{ $steuersatz }}, kaufmännisch gerundet
                            </td>
                            <td class="betrag" data-label="Betrag">{{ $euro($beispielNettoCent) }}</td>
                        </tr>
                        <tr>
                            <th scope="row" class="font-medium">davon Umsatzsteuer</th>
                            <td class="text-hvm-text-sekundaer" data-label="Rechenweg">
                                {{ $steuersatz }} Prozent, ermittelt als Differenz von Brutto und Netto
                            </td>
                            <td class="betrag" data-label="Betrag">{{ $euro($beispielSteuerCent) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="mt-5 max-w-prose text-sm leading-relaxed text-hvm-text-sekundaer">
                Der Steuerausweis erfolgt auf den Gesamtbetrag der Rechnung. Gegenüber der Multiplikation der einzelnen
                Nettobeträge können sich daher Rundungsdifferenzen von wenigen Cent ergeben.
            </p>
        </div>
    </section>

    {{-- Zahlung und Rechnung --}}
    <section class="border-t border-hvm-linie bg-white">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <x-hvm.section-heading
                level="h2"
                eyebrow="Zahlung und Rechnung"
                title="Wann Sie zahlen und was Sie erhalten" />

            <div class="mt-12 grid gap-5 lg:grid-cols-2">
                <x-hvm.card title="Zahlung erst nach der Vorschau" tone="canvas" :accent="true">
                    <p class="text-hvm-text-sekundaer">
                        Vor der Vorschau erhalten Sie eine unverbindliche Schätzung. Vor dem Bezahlvorgang wird der
                        Endpreis anhand der tatsächlich erzeugten Mieterabrechnungen erneut berechnet und Ihnen
                        angezeigt. Die Freigabe der Abrechnungen ohne Wasserzeichen erfolgt erst nach bestätigter
                        Zahlung.
                    </p>
                </x-hvm.card>

                <x-hvm.card title="Rechnung" tone="canvas" :accent="true">
                    <p class="text-hvm-text-sekundaer">
                        Die Rechnung stellt die {{ $operator['legal_name'] }}, {{ $operator['address_line'] }},
                        {{ $operator['postal_code'] }} {{ $operator['city'] }}. Netto, Umsatzsteuer und Brutto werden
                        getrennt ausgewiesen. Sie finden die Rechnung dauerhaft in Ihrem Konto.
                    </p>
                </x-hvm.card>
            </div>

            <div class="mt-5 grid gap-5 lg:grid-cols-2">
                <x-hvm.alert variant="success" title="Kein Abonnement">
                    Es gibt keine Laufzeit, keine automatische Verlängerung und keine versteckten wiederkehrenden
                    Beträge. Auch die Erinnerungsfunktion für Folgejahre ist Teil des kostenlosen Kontos und löst keine
                    Zahlung aus.
                </x-hvm.alert>

                <x-hvm.alert variant="warning" title="Korrekturen nach der Zahlung">
                    Eine finalisierte Abrechnung wird nie überschrieben. Eine Korrektur erzeugt eine neue Version. Ob
                    dafür erneut ein Betrag anfällt, wird Ihnen vorher angezeigt und muss von Ihnen bestätigt werden.
                </x-hvm.alert>
            </div>

            <div class="mt-14 flex flex-wrap justify-center gap-3">
                <x-hvm.button href="{{ url('/app') }}" variant="primary" size="lg" class="w-full sm:w-auto">Kostenlos starten</x-hvm.button>
                <x-hvm.button href="{{ route('site.faq') }}" variant="secondary" size="lg" class="w-full sm:w-auto">Häufige Fragen</x-hvm.button>
            </div>
        </div>
    </section>
@endsection
