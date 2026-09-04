{{--
    Schritt 11: Zahlung (Masterprompt 9 Schritt 11, 15.1, 2.3)

    Der angezeigte Preis ist der serverseitig berechnete Bruttopreis. Netto und
    Umsatzsteuer werden getrennt ausgewiesen (ADR-010). Es gibt in diesem
    Formular bewusst KEIN Betrags- und kein Mengenfeld: Der Preis wird beim
    Absenden erneut serverseitig aus der tatsaechlichen Anzahl der erzeugten
    Mieterabrechnungen gebildet.

    Beide Kaestchen sind Pflicht und NICHT vorangekreuzt.
--}}
@extends('layouts.portal')

@section('titel', 'Zahlung Abrechnung '.$lauf->billing_year)

@section('content')
    <x-hvm.section-heading
        eyebrow="Schritt 11 von 12"
        title="Zahlung"
        lead="Sie zahlen einmalig je erzeugter Mieterabrechnung. Es entsteht kein Abonnement." />

    @if (session('status'))
        <div class="mt-6">
            <x-hvm.alert variant="info">{{ session('status') }}</x-hvm.alert>
        </div>
    @endif

    @error('zahlung')
        <div class="mt-6">
            <x-hvm.alert variant="error" title="Die Zahlung wurde nicht eingeleitet">{{ $message }}</x-hvm.alert>
        </div>
    @enderror

    <div class="mt-8 grid gap-6 lg:grid-cols-2">
        <x-hvm.card title="Ihre Abrechnung" accent>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-4">
                    <dt>Objekt</dt>
                    <dd class="text-right font-semibold">{{ $objekt?->label ?? 'Objekt' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt>Abrechnungszeitraum</dt>
                    <dd class="text-right font-semibold">
                        {{ $lauf->period_start?->format('d.m.Y') }} bis {{ $lauf->period_end?->format('d.m.Y') }}
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt>Erzeugte Mieterabrechnungen</dt>
                    <dd class="text-right font-semibold">{{ $anzahl }}</dd>
                </div>
            </dl>

            <p class="mt-4 text-sm text-hvm-anthrazit">
                Abgerechnet wird je erzeugter Mieterabrechnung, nicht je Wohnung. Bei einem Mieterwechsel entstehen
                für eine Einheit mehrere Abrechnungen.
            </p>
        </x-hvm.card>

        <x-hvm.card title="Preis">
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-4">
                    <dt>{{ $anzahl }} Mieterabrechnungen zu je {{ $preis->unitGross()->format() }} brutto</dt>
                    <dd class="text-right">{{ $preis->gross()->format() }}</dd>
                </div>

                @if ($preis->hasBaseAmount())
                    <div class="flex justify-between gap-4">
                        <dt>Grundpreis je Abrechnungslauf</dt>
                        <dd class="text-right">{{ $preis->base()->format() }}</dd>
                    </div>
                @endif

                <div class="flex justify-between gap-4 border-t border-hvm-hellgrau pt-2">
                    <dt>Netto</dt>
                    <dd class="text-right">{{ $preis->net()->format() }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt>Umsatzsteuer {{ $preis->vatRatePercent }} Prozent</dt>
                    <dd class="text-right">{{ $preis->tax()->format() }}</dd>
                </div>
                <div class="flex justify-between gap-4 border-t border-hvm-mittelgrau pt-2 text-base font-bold">
                    <dt>Gesamtbetrag brutto</dt>
                    <dd class="text-right">{{ $preis->gross()->format() }}</dd>
                </div>
            </dl>

            <p class="mt-4 text-sm text-hvm-anthrazit">
                Der Betrag wird unmittelbar vor der Zahlung erneut serverseitig aus der tatsächlichen Anzahl Ihrer
                Abrechnungen berechnet.
            </p>
        </x-hvm.card>
    </div>

    @if ($bestaetigungFehlt)
        <div class="mt-6">
            <x-hvm.alert variant="warning" title="Bestätigung fehlt noch">
                Bitte bestätigen Sie zuerst in der Vorschau (Schritt 10), dass Sie alle Werte, Umlageschlüssel
                und Ergebnisse geprüft haben und als Vermieter für die Abrechnung verantwortlich sind.
                <span class="mt-2 block">
                    <a class="underline underline-offset-2"
                       href="{{ route('portal.wizard.vorschau', ['billingRun' => $lauf->getKey()]) }}">Zur Vorschau</a>
                </span>
            </x-hvm.alert>
        </div>
    @endif

    {{--
        VOR LIVEGANG DURCH RECHTSANWALT PRÜFEN UND FREIGEBEN

        Die folgenden beiden Texte sind Platzhalterfassungen. Sie stehen in
        App\Application\Payment\CheckoutTexts, damit genau der angezeigte
        Wortlaut protokolliert und gehasht wird (Abschnitt 2.3). Die gesonderte
        Zustimmung zur sofortigen Vertragsausfuehrung ist NICHT vorangekreuzt.
    --}}
    <x-hvm.card class="mt-8" title="Ihre Zustimmungen">
        <x-hvm.legal-placeholder-banner />

        <form method="POST" action="{{ route('portal.checkout.store', ['billingRun' => $lauf->getKey()]) }}"
              class="mt-6 space-y-5">
            @csrf

            <div class="flex items-start gap-3">
                <input id="sofortige_ausfuehrung" name="sofortige_ausfuehrung" type="checkbox" value="1"
                       class="mt-1 h-5 w-5 rounded border-hvm-mittelgrau">
                <label for="sofortige_ausfuehrung" class="text-sm text-hvm-textschwarz">
                    {{ $textSofortigeAusfuehrung }}
                </label>
            </div>

            @error('sofortige_ausfuehrung')
                <p class="text-sm font-semibold text-status-error">{{ $message }}</p>
            @enderror

            <div class="flex items-start gap-3">
                <input id="vertragsgrundlagen" name="vertragsgrundlagen" type="checkbox" value="1"
                       class="mt-1 h-5 w-5 rounded border-hvm-mittelgrau">
                <label for="vertragsgrundlagen" class="text-sm text-hvm-textschwarz">
                    {{ $textVertragsgrundlagen }}
                </label>
            </div>

            @error('vertragsgrundlagen')
                <p class="text-sm font-semibold text-status-error">{{ $message }}</p>
            @enderror

            <p class="text-xs text-hvm-anthrazit">
                Textfassung {{ $textfassung }}. Ihre Zustimmung wird mit Zeitpunkt, gekürzter IP-Adresse und
                gehashtem Browserkennzeichen protokolliert.
            </p>

            <div class="flex flex-wrap gap-3">
                <x-hvm.button type="submit" variant="primary" size="lg">
                    Kostenpflichtig zahlen: {{ $preis->gross()->format() }}
                </x-hvm.button>

                <x-hvm.button href="{{ route('portal.abrechnungen.show', ['billingRun' => $lauf->getKey()]) }}"
                              variant="secondary">
                    Zurück zur Abrechnung
                </x-hvm.button>
            </div>
        </form>
    </x-hvm.card>

    <p class="mt-8 text-sm text-hvm-anthrazit">
        Die Zahlung läuft über die gehostete Zahlungsseite von Stripe. An den Zahlungsanbieter werden ausschließlich
        eine neutrale Leistungsbezeichnung, die Anzahl der Abrechnungen und der Betrag übermittelt. Mietverträge,
        Abrechnungsbelege und Mieterabrechnungen erhält der Zahlungsanbieter nicht.
    </p>

    @if ($rechnungsblocker['blockiert'])
        {{--
            Sichtbarer Hinweis fuer den internen Betrieb. Solange
            Pflichtangaben fehlen, wird keine produktive Rechnung erzeugt
            (Abschnitt 15.2).
        --}}
        <div class="mt-6">
            <x-hvm.alert variant="warning" title="Hinweis zur Rechnungsstellung">
                {{ $rechnungsblocker['hinweis'] }}
            </x-hvm.alert>
        </div>
    @endif
@endsection
