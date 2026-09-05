{{--
    Schritt 11: Zahlung (Masterprompt 9 Schritt 11, 15.1, 2.3)

    Der angezeigte Preis ist der serverseitig berechnete Bruttopreis. Netto und
    Umsatzsteuer werden getrennt ausgewiesen (ADR-010). Es gibt in diesem
    Formular bewusst KEIN Betrags- und kein Mengenfeld: Der Preis wird beim
    Absenden erneut serverseitig aus der tatsaechlichen Anzahl der erzeugten
    Mieterabrechnungen gebildet.

    Beide Kaestchen sind Pflicht und NICHT vorangekreuzt.

    Liegt der konfigurierte Preis ausserhalb des Korridors, wird keine
    Zahlungsschaltflaeche angeboten, sondern eine verstaendliche Meldung.

    Gestaltung nach docs/designsystem.md: Seitenkopf (4.3), Karten (4.5),
    Formular mit x-hvm.field (4.6), Meldungen (4.14), ein Primaerbutton (4.12).
--}}
@extends('layouts.portal')

@section('titel', 'Zahlung Abrechnung '.$lauf->billing_year)

@section('content')
    <x-hvm.page-header
        :eyebrow="$schritt->eyebrow()"
        title="Zahlung"
        lead="Sie zahlen einmalig je erzeugter Mieterabrechnung. Es entsteht kein Abonnement." />

    <div class="mt-8">
        @include('portal.wizard.partials.fortschritt', [
            'fortschritt' => $fortschritt,
            'billingRun' => $lauf,
            'wiedereinstieg' => null,
        ])
    </div>

    @error('zahlung')
        <div class="mt-8">
            <x-hvm.alert variant="error" title="Die Zahlung wurde nicht eingeleitet">{{ $message }}</x-hvm.alert>
        </div>
    @enderror

    @if ($preisfehler !== null)
        <div class="mt-8">
            <x-hvm.alert variant="error" title="Die Zahlung ist derzeit nicht möglich">
                {{ $preisfehler }} Bitte wenden Sie sich an den Support.
            </x-hvm.alert>
        </div>
    @endif

    <div class="mt-10 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-hvm.card class="min-w-0" title="Ihre Abrechnung" eyebrow="Grundlage" accent>
            <dl class="divide-y divide-hvm-linie text-sm">
                <div class="flex items-baseline justify-between gap-4 py-3 first:pt-0">
                    <dt class="text-hvm-text-sekundaer">Objekt</dt>
                    <dd class="text-right font-semibold text-hvm-textschwarz">{{ $objekt?->label ?? 'Objekt' }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-4 py-3">
                    <dt class="text-hvm-text-sekundaer">Abrechnungszeitraum</dt>
                    <dd class="text-right font-semibold text-hvm-textschwarz tabular">
                        {{ $lauf->period_start?->format('d.m.Y') }} bis {{ $lauf->period_end?->format('d.m.Y') }}
                    </dd>
                </div>
                <div class="flex items-baseline justify-between gap-4 py-3 last:pb-0">
                    <dt class="text-hvm-text-sekundaer">Erzeugte Mieterabrechnungen</dt>
                    <dd class="text-right font-semibold text-hvm-textschwarz tabular">{{ $anzahl }}</dd>
                </div>
            </dl>

            <p class="mt-5 text-sm leading-relaxed text-hvm-text-sekundaer">
                Abgerechnet wird je erzeugter Mieterabrechnung, nicht je Wohnung. Bei einem Mieterwechsel entstehen
                für eine Einheit mehrere Abrechnungen.
            </p>
        </x-hvm.card>

        @if ($preis !== null)
            <x-hvm.card class="min-w-0" title="Preis" eyebrow="Einmalig">
                <dl class="divide-y divide-hvm-linie text-sm">
                    <div class="flex items-baseline justify-between gap-4 py-3 first:pt-0">
                        <dt class="text-hvm-textschwarz">{{ $anzahl }} Mieterabrechnungen zu je {{ $preis->unitGross()->format() }} brutto</dt>
                        <dd class="text-right whitespace-nowrap text-hvm-textschwarz tabular">{{ $preis->gross()->format() }}</dd>
                    </div>

                    @if ($preis->hasBaseAmount())
                        <div class="flex items-baseline justify-between gap-4 py-3">
                            <dt class="text-hvm-textschwarz">Grundpreis je Abrechnungslauf</dt>
                            <dd class="text-right whitespace-nowrap text-hvm-textschwarz tabular">{{ $preis->base()->format() }}</dd>
                        </div>
                    @endif

                    <div class="flex items-baseline justify-between gap-4 py-3">
                        <dt class="text-hvm-text-sekundaer">Netto</dt>
                        <dd class="text-right whitespace-nowrap text-hvm-textschwarz tabular">{{ $preis->net()->format() }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-4 py-3">
                        <dt class="text-hvm-text-sekundaer">Umsatzsteuer {{ $preis->vatRatePercent }} Prozent</dt>
                        <dd class="text-right whitespace-nowrap text-hvm-textschwarz tabular">{{ $preis->tax()->format() }}</dd>
                    </div>
                </dl>

                <div class="mt-3 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 rounded-2xl bg-hvm-canvas p-4">
                    <p class="text-sm font-semibold text-hvm-textschwarz">Gesamtbetrag brutto</p>
                    <p class="text-3xl font-semibold tracking-tight whitespace-nowrap text-hvm-textschwarz tabular">{{ $preis->gross()->format() }}</p>
                </div>

                <p class="mt-5 text-sm leading-relaxed text-hvm-text-sekundaer">
                    Der Betrag wird unmittelbar vor der Zahlung erneut serverseitig aus der tatsächlichen Anzahl Ihrer
                    Abrechnungen berechnet.
                </p>
            </x-hvm.card>
        @endif
    </div>

    {{-- Offene Punkte vor der Zahlung in einer Meldung (4.14), nicht als Stapel. --}}
    @if ($bestaetigungFehlt || $anschriftFehlt)
        <div class="mt-8">
            <x-hvm.alert variant="info" label="Fehlt noch">
                <ul class="space-y-3">
                    @if ($bestaetigungFehlt)
                        <li>
                            <p class="font-semibold">Bestätigung fehlt noch</p>
                            Bitte bestätigen Sie zuerst in der Vorschau (Schritt 10), dass Sie alle Werte, Umlageschlüssel
                            und Ergebnisse geprüft haben und als Vermieter für die Abrechnung verantwortlich sind.
                            <span class="mt-2 block">
                                <a class="font-medium underline underline-offset-4"
                                   href="{{ route('portal.wizard.vorschau', ['billingRun' => $lauf->getKey()]) }}">Zur Vorschau</a>
                            </span>
                        </li>
                    @endif
                    @if ($anschriftFehlt)
                        <li>
                            <p class="font-semibold">Rechnungsanschrift fehlt noch</p>
                            Für die Rechnung der Hausverwaltung Müller GmbH benötigen wir Ihre vollständige Rechnungsanschrift
                            (Straße und Hausnummer, Postleitzahl, Ort). Bitte ergänzen Sie sie unter
                            <a href="{{ route('portal.konto.edit') }}" class="font-semibold underline underline-offset-4">Konto</a>, bevor Sie die
                            Zahlung einleiten. Eine festgeschriebene Rechnung kann nachträglich nicht geändert werden.
                        </li>
                    @endif
                </ul>
            </x-hvm.alert>
        </div>
    @endif

    @if ($preis !== null)
        {{--
            VOR LIVEGANG DURCH RECHTSANWALT PRÜFEN UND FREIGEBEN

            Die folgenden beiden Texte sind Platzhalterfassungen. Sie stehen in
            App\Application\Payment\CheckoutTexts, damit genau der angezeigte
            Wortlaut protokolliert und gehasht wird (Abschnitt 2.3). Die gesonderte
            Zustimmung zur sofortigen Vertragsausfuehrung ist NICHT vorangekreuzt.
        --}}
        <x-hvm.card class="mt-10 rounded-3xl" :kennlinie="true" padding="none">
            <div class="p-6 sm:p-8">
                <p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">Zustimmung</p>
                <h2 class="mt-1 text-2xl font-semibold tracking-tight text-hvm-textschwarz">Ihre Zustimmungen</h2>

                <div class="mt-6">
                    <x-hvm.legal-placeholder-banner />
                </div>

                <form method="POST" action="{{ route('portal.checkout.store', ['billingRun' => $lauf->getKey()]) }}"
                      class="mt-6 space-y-6">
                    @csrf

                    <x-hvm.field
                        name="sofortige_ausfuehrung"
                        id="sofortige_ausfuehrung"
                        type="checkbox"
                        value="1"
                        :label="$textSofortigeAusfuehrung" />

                    <x-hvm.field
                        name="vertragsgrundlagen"
                        id="vertragsgrundlagen"
                        type="checkbox"
                        value="1"
                        :label="$textVertragsgrundlagen" />

                    <p class="text-xs leading-relaxed text-hvm-text-sekundaer">
                        Textfassung {{ $textfassung }}. Ihre Zustimmung wird mit Zeitpunkt, gekürzter IP-Adresse und
                        gehashtem Browserkennzeichen protokolliert.
                    </p>

                    <div class="flex flex-wrap gap-3 border-t border-hvm-linie pt-6">
                        <x-hvm.button type="submit" variant="primary" size="lg">
                            <x-hvm.icon name="lock" class="h-5 w-5" />
                            Kostenpflichtig zahlen: {{ $preis->gross()->format() }}
                        </x-hvm.button>

                        <x-hvm.button href="{{ route('portal.abrechnungen.show', ['billingRun' => $lauf->getKey()]) }}"
                                      variant="secondary" size="lg">
                            Zurück zur Abrechnung
                        </x-hvm.button>
                    </div>
                </form>
            </div>
        </x-hvm.card>
    @else
        <div class="mt-10">
            <x-hvm.button href="{{ route('portal.abrechnungen.show', ['billingRun' => $lauf->getKey()]) }}"
                          variant="secondary">
                Zurück zur Abrechnung
            </x-hvm.button>
        </div>
    @endif

    <div class="mt-10 flex gap-3 max-w-prose">
        <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark" aria-hidden="true">
            <x-hvm.icon name="shield" class="h-4 w-4" />
        </span>
        <div class="min-w-0 text-sm leading-relaxed text-hvm-text-sekundaer">
            <p>
                Die Zahlung läuft über die gehostete Zahlungsseite von Stripe. An den Zahlungsanbieter werden ausschließlich
                eine neutrale Leistungsbezeichnung, die Anzahl der Abrechnungen und der Betrag übermittelt. Mietverträge,
                Abrechnungsbelege und Mieterabrechnungen erhält der Zahlungsanbieter nicht.
            </p>

            @if ($rechnungsblocker['blockiert'])
                {{--
                    Kundenansicht. Die fehlenden Betreiberangaben selbst werden hier
                    nicht genannt; sie sind eine interne Angabe des Betriebs und im
                    Adminbereich sichtbar (Abschnitt 15.2).
                --}}
                <p class="mt-3">
                    Die Rechnung der Hausverwaltung Müller GmbH zu Ihrer Zahlung wird Ihnen nachgereicht und im
                    Abschlussbereich Ihres Kontos bereitgestellt.
                </p>
            @endif
        </div>
    </div>
@endsection
