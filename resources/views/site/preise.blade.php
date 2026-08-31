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
    <section class="border-b border-hvm-umrissgrau bg-hvm-umrissgrau">
        <div class="mx-auto max-w-4xl px-4 py-12 sm:px-6 sm:py-16">
            <x-hvm.badge variant="akzent">Preise</x-hvm.badge>
            <h1 class="mt-5 text-3xl font-bold text-hvm-anthrazit sm:text-4xl">
                Ein Festpreis je erzeugter Mieterabrechnung
            </h1>
            <p class="mt-5 text-lg leading-relaxed text-hvm-textschwarz">
                Das Konto und alle Entwürfe sind kostenlos. Bezahlt wird erst, wenn Sie die Vorschau geprüft haben und
                die Abrechnungen ohne Wasserzeichen erhalten möchten. Es entsteht kein Abonnement.
            </p>
        </div>
    </section>

    <div class="mx-auto max-w-4xl px-4 py-12 sm:px-6 sm:py-16">
        {{-- Preisblock --}}
        <section>
            <div class="grid gap-6 sm:grid-cols-2">
                <div class="rounded-lg border border-hvm-hellgrau bg-white p-6">
                    <p class="text-sm font-semibold tracking-wide text-hvm-textschwarz uppercase">Je Mieterabrechnung</p>
                    <p class="mt-3 text-4xl font-bold text-hvm-anthrazit">{{ $euro($bruttoJeAbrechnungCent) }}</p>
                    <p class="mt-2 text-sm text-hvm-textschwarz">brutto, inklusive Umsatzsteuer</p>

                    <dl class="mt-6 space-y-2 text-base text-hvm-textschwarz">
                        <div class="flex justify-between gap-4">
                            <dt>Netto</dt>
                            <dd class="font-medium">{{ $euro($nettoJeAbrechnungCent) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt>Umsatzsteuer {{ $steuersatz }} Prozent</dt>
                            <dd class="font-medium">{{ $euro($steuerJeAbrechnungCent) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 border-t border-hvm-mittelgrau pt-2">
                            <dt class="font-semibold">Brutto</dt>
                            <dd class="font-semibold">{{ $euro($bruttoJeAbrechnungCent) }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="rounded-lg border border-hvm-hellgrau bg-white p-6">
                    <p class="text-sm font-semibold tracking-wide text-hvm-textschwarz uppercase">Immer enthalten</p>
                    <ul class="mt-4 space-y-2 text-base text-hvm-textschwarz">
                        <li>Konto, Objekte und Entwürfe kostenlos</li>
                        <li>Auswertung Ihrer Unterlagen und Prüfbericht</li>
                        <li>Vorschau aller Abrechnungen mit Wasserzeichen</li>
                        <li>Einzel-PDFs, ZIP-Datei und Eigentümerübersicht nach Zahlung</li>
                        <li>Übernahme der Daten in das Folgejahr</li>
                        <li>Erinnerungen für Folgejahre, jederzeit abschaltbar</li>
                    </ul>

                    <p class="mt-5 text-base text-hvm-textschwarz">
                        Grundpreis je Abrechnungslauf:
                        <span class="font-semibold">{{ $euro($grundpreisCent) }}</span>
                        @if ($grundpreisCent === 0)
                            <span class="block text-sm">Es fällt derzeit kein Grundpreis an.</span>
                        @endif
                    </p>

                    <div class="mt-6">
                        <x-hvm.button href="{{ url('/app') }}" variant="primary">Kostenlos starten</x-hvm.button>
                    </div>
                </div>
            </div>
        </section>

        {{-- Abrechnungseinheit --}}
        <section class="mt-14">
            <x-hvm.section-heading
                level="h2"
                eyebrow="Abrechnungseinheit"
                title="Gezählt wird die erzeugte Mieterabrechnung"
                lead="Nicht die Wohnung ist die Abrechnungseinheit für den Preis, sondern die einzelne Mieterabrechnung. Wechselt der Mieter im Abrechnungszeitraum, entstehen für dieselbe Einheit mehrere Abrechnungen." />

            <div class="mt-6 grid gap-6 sm:grid-cols-2">
                <x-hvm.card title="Eine Einheit, ein Mietverhältnis">
                    Eine Wohnung, durchgehend an dieselben Mieter vermietet, ergibt eine Mieterabrechnung.
                </x-hvm.card>

                <x-hvm.card title="Eine Einheit, ein Mieterwechsel">
                    Zieht ein Mieter aus und ein neuer ein, werden zwei Nutzungszeiträume abgerechnet und damit zwei
                    Mieterabrechnungen erzeugt.
                </x-hvm.card>
            </div>

            <div class="mt-6">
                <x-hvm.alert variant="info" title="Leerstand">
                    Für eine leerstehende Einheit entsteht keine Mieterabrechnung und damit kein Preis. Die auf den
                    Leerstand entfallenden Kosten bleiben beim Eigentümer und erscheinen in der Eigentümerübersicht.
                </x-hvm.alert>
            </div>
        </section>

        {{-- Rechenbeispiel --}}
        <section class="mt-14">
            <x-hvm.section-heading
                level="h2"
                eyebrow="Rechenbeispiel"
                title="{{ $beispielEinheiten }} Einheiten mit einem Mieterwechsel" />

            <p class="mt-4 text-base leading-relaxed text-hvm-textschwarz">
                Ein Mehrfamilienhaus mit {{ $beispielEinheiten }} vermieteten Einheiten. In einer Einheit wechselt der
                Mieter im Abrechnungsjahr. Daraus ergeben sich {{ $beispielAbrechnungen }} Mieterabrechnungen.
            </p>

            <div class="mt-6 overflow-x-auto">
                <table class="hvm-table-zebra w-full border-collapse text-left text-base">
                    <caption class="sr-only">
                        Rechenbeispiel für {{ $beispielAbrechnungen }} Mieterabrechnungen
                    </caption>
                    <thead>
                        <tr class="bg-hvm-orange text-hvm-textschwarz">
                            <th scope="col" class="px-4 py-3 font-semibold">Position</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Rechenweg</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Betrag</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-hvm-hellgrau">
                            <th scope="row" class="px-4 py-3 font-medium">Mieterabrechnungen</th>
                            <td class="px-4 py-3">
                                {{ $beispielEinheiten }} Einheiten plus {{ $beispielWechsel }} Mieterwechsel
                            </td>
                            <td class="px-4 py-3 text-right">{{ $beispielAbrechnungen }} Stück</td>
                        </tr>
                        <tr class="border-b border-hvm-hellgrau">
                            <th scope="row" class="px-4 py-3 font-medium">Preis je Abrechnung</th>
                            <td class="px-4 py-3">Festpreis brutto</td>
                            <td class="px-4 py-3 text-right">{{ $euro($bruttoJeAbrechnungCent) }}</td>
                        </tr>
                        <tr class="border-b border-hvm-hellgrau">
                            <th scope="row" class="px-4 py-3 font-medium">Grundpreis</th>
                            <td class="px-4 py-3">je Abrechnungslauf</td>
                            <td class="px-4 py-3 text-right">{{ $euro($grundpreisCent) }}</td>
                        </tr>
                        <tr class="border-b border-hvm-hellgrau">
                            <th scope="row" class="px-4 py-3 font-semibold">Summe brutto</th>
                            <td class="px-4 py-3">
                                {{ $beispielAbrechnungen }} mal {{ $euro($bruttoJeAbrechnungCent) }} plus {{ $euro($grundpreisCent) }}
                            </td>
                            <td class="px-4 py-3 text-right font-semibold">{{ $euro($beispielBruttoCent) }}</td>
                        </tr>
                        <tr class="border-b border-hvm-hellgrau">
                            <th scope="row" class="px-4 py-3 font-medium">davon netto</th>
                            <td class="px-4 py-3">
                                {{ $euro($beispielBruttoCent) }} geteilt durch 1,{{ $steuersatz }}, kaufmännisch gerundet
                            </td>
                            <td class="px-4 py-3 text-right">{{ $euro($beispielNettoCent) }}</td>
                        </tr>
                        <tr>
                            <th scope="row" class="px-4 py-3 font-medium">davon Umsatzsteuer</th>
                            <td class="px-4 py-3">
                                {{ $steuersatz }} Prozent, ermittelt als Differenz von Brutto und Netto
                            </td>
                            <td class="px-4 py-3 text-right">{{ $euro($beispielSteuerCent) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="mt-4 text-sm text-hvm-textschwarz">
                Der Steuerausweis erfolgt auf den Gesamtbetrag der Rechnung. Gegenüber der Multiplikation der einzelnen
                Nettobeträge können sich daher Rundungsdifferenzen von wenigen Cent ergeben.
            </p>
        </section>

        {{-- Zahlung und Rechnung --}}
        <section class="mt-14">
            <x-hvm.section-heading
                level="h2"
                eyebrow="Zahlung und Rechnung"
                title="Wann Sie zahlen und was Sie erhalten" />

            <div class="mt-6 grid gap-6 sm:grid-cols-2">
                <x-hvm.card title="Zahlung erst nach der Vorschau">
                    <p>
                        Vor der Vorschau erhalten Sie eine unverbindliche Schätzung. Vor dem Bezahlvorgang wird der
                        Endpreis anhand der tatsächlich erzeugten Mieterabrechnungen erneut berechnet und Ihnen
                        angezeigt. Die Freigabe der Abrechnungen ohne Wasserzeichen erfolgt erst nach bestätigter
                        Zahlung.
                    </p>
                </x-hvm.card>

                <x-hvm.card title="Rechnung">
                    <p>
                        Die Rechnung stellt die {{ $operator['legal_name'] }}, {{ $operator['address_line'] }},
                        {{ $operator['postal_code'] }} {{ $operator['city'] }}. Netto, Umsatzsteuer und Brutto werden
                        getrennt ausgewiesen. Sie finden die Rechnung dauerhaft in Ihrem Konto.
                    </p>
                </x-hvm.card>
            </div>

            <div class="mt-6 space-y-4">
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

            <div class="mt-8 flex flex-wrap gap-3">
                <x-hvm.button href="{{ url('/app') }}" variant="primary" size="lg">Kostenlos starten</x-hvm.button>
                <x-hvm.button href="{{ route('site.faq') }}" variant="secondary" size="lg">Häufige Fragen</x-hvm.button>
            </div>
        </section>
    </div>
@endsection
