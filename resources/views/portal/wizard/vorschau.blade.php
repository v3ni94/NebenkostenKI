{{--
    Schritt 10: Vorschau und Bestaetigung.

    Genau ein Primaerbutton je Ansicht: ohne gueltige Vorschau ist es
    "Vorschau erzeugen", mit gueltiger Vorschau die Bestaetigung (bzw. "Zur
    Zahlung"), das erneute Erzeugen wird dann zur Nebenhandlung.
--}}
@extends('layouts.portal')

@section('titel', 'Vorschau')

@section('content')
    <x-hvm.page-header
        eyebrow="Geführter Ablauf"
        title="Schritt 10: Vorschau und Bestätigung"
        lead="Alle Mieterabrechnungen und die Eigentümerübersicht werden serverseitig erzeugt. Jede Seite trägt ein Wasserzeichen." />

    <div class="mt-8">
        @include('portal.wizard.partials.fortschritt', [
            'fortschritt' => $fortschritt,
            'billingRun' => $billingRun,
            'wiedereinstieg' => $wiedereinstieg,
        ])
    </div>

    @if ($sperrgrund !== null)
        <x-hvm.alert variant="error" class="mt-8" label="Blockiert die Abrechnung">
            <p>{{ $sperrgrund }}</p>
        </x-hvm.alert>
    @endif

    <div class="mt-10 grid grid-cols-1 gap-6 lg:grid-cols-12">
        <x-hvm.card class="min-w-0 lg:col-span-7" title="Unverbindliche Preisschätzung" eyebrow="Kosten">
            <p class="max-w-prose">{{ $schaetzung->explanation() }}</p>
            <p class="mt-4 text-xl font-semibold tracking-tight text-hvm-textschwarz sm:text-2xl">Voraussichtlich <span class="tabular whitespace-nowrap">{{ $schaetzung->totalGross->format() }}</span> brutto</p>
            <p class="mt-2 text-sm leading-relaxed text-hvm-text-sekundaer">{{ $schaetzung->hint() }}</p>
        </x-hvm.card>

        <x-hvm.card tone="canvas" class="min-w-0 lg:col-span-5" title="Vorschau erzeugen" eyebrow="Dokumente">
            <form method="POST"
                  action="{{ route('portal.wizard.vorschau.erzeugen', ['billingRun' => $billingRun->getKey()]) }}">
                @csrf
                <x-hvm.button type="submit" :variant="$gueltig ? 'secondary' : 'primary'">
                    <x-hvm.icon name="sparkle" class="h-4 w-4" />
                    {{ $gueltig ? 'Vorschau neu erzeugen' : 'Vorschau erzeugen' }}
                </x-hvm.button>
            </form>
        </x-hvm.card>
    </div>

    @if (! $gueltig)
        <x-hvm.alert variant="info" class="mt-6" label="Fehlt noch">
            <p>
                Es liegt noch keine gültige Vorschau vor. Nach jeder abrechnungsrelevanten Änderung wird eine
                frühere Vorschau ungültig und muss neu erzeugt werden.
            </p>
        </x-hvm.alert>
    @else
        <x-hvm.alert variant="info" class="mt-6" label="Hinweis" title="Vorschau mit Wasserzeichen">
            <p>
                Jede Seite trägt ein serverseitig eingebranntes Wasserzeichen. Ein Download ist in dieser Phase
                ausschließlich mit Wasserzeichen möglich. Das Wasserzeichen ist ein wirksames Hemmnis gegen die
                Verwendung, aber keine absolute Kopiersperre.
            </p>
        </x-hvm.alert>

        <section class="mt-16" aria-labelledby="ueberschrift-dokumente">
            <div class="min-w-0">
                <p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">Vorschau</p>
                <h2 id="ueberschrift-dokumente" class="mt-1 text-2xl font-semibold tracking-tight text-hvm-textschwarz">Dokumente mit Wasserzeichen</h2>
            </div>

            <div class="mt-6 space-y-6">
                @foreach ($dokumente as $dokument)
                    <x-hvm.card :title="$dokument->titel">
                        <p class="text-sm text-hvm-text-sekundaer">
                            {{ $dokument->untertitel }} {{ $dokument->seiten }} Seiten.
                        </p>

                        <div class="mt-4 h-[600px] overflow-auto rounded-2xl border border-hvm-linie bg-hvm-canvas-deep">
                            <object class="h-full w-full"
                                    type="application/pdf"
                                    data="{{ route('portal.downloads.stream', ['generatedDocument' => $dokument->id()]) }}"
                                    aria-label="{{ $dokument->titel }} als Vorschau">
                                <p class="p-5 text-sm leading-relaxed text-hvm-textschwarz">
                                    Ihr Browser kann die Vorschau nicht direkt anzeigen. Sie können das Dokument mit
                                    Wasserzeichen öffnen.
                                </p>
                            </object>
                        </div>

                        <p class="mt-4 text-sm">
                            <a class="inline-flex min-h-11 items-center gap-2 font-medium text-hvm-textschwarz underline decoration-hvm-orange decoration-2 underline-offset-4 hover:decoration-hvm-orange-dark"
                               href="{{ route('portal.downloads.stream', ['generatedDocument' => $dokument->id()]) }}">
                                <x-hvm.icon name="document" class="h-4 w-4" />
                                Dokument mit Wasserzeichen öffnen
                            </a>
                        </p>
                    </x-hvm.card>
                @endforeach
            </div>
        </section>

        <x-hvm.card :kennlinie="true" padding="none" class="mt-10 rounded-3xl">
            <div class="p-6 sm:p-8">
                <p class="text-xs font-semibold tracking-[0.08em] text-hvm-text-sekundaer uppercase">Bestätigung</p>
                <h2 class="mt-2 text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl">Ihre Bestätigung vor der Zahlung</h2>

                @if ($bestaetigt)
                    <x-hvm.alert variant="success" label="Erledigt" class="mt-5">
                        <p>Ihre Bestätigung ist protokolliert. Sie können zur Zahlung fortfahren.</p>
                    </x-hvm.alert>

                    <div class="mt-6">
                        <x-hvm.button href="{{ route('portal.checkout.show', ['billingRun' => $billingRun->getKey()]) }}"
                                      variant="primary" size="lg">
                            Zur Zahlung
                            <x-hvm.icon name="arrow-right" class="h-5 w-5" />
                        </x-hvm.button>
                    </div>
                @else
                    <form method="POST" class="mt-5 space-y-6"
                          action="{{ route('portal.wizard.vorschau.bestaetigen', ['billingRun' => $billingRun->getKey()]) }}">
                        @csrf

                        <x-hvm.field name="bestaetigung" :label="$bestaetigungstext" type="checkbox" value="1" :required="true" :checked="false" />

                        <p class="max-w-prose text-sm leading-relaxed text-hvm-text-sekundaer">
                            Textfassung {{ $textversion }}. Wir protokollieren Zweck, Zeitpunkt, gekürzte IP-Adresse und
                            einen Hashwert Ihres Browserkennzeichens. VOR LIVEGANG DURCH RECHTSANWALT PRÜFEN UND
                            FREIGEBEN.
                        </p>

                        <x-hvm.button type="submit" variant="primary" size="lg">
                            Bestätigen und zur Zahlung fortfahren
                            <x-hvm.icon name="arrow-right" class="h-5 w-5" />
                        </x-hvm.button>
                    </form>
                @endif
            </div>
        </x-hvm.card>
    @endif
@endsection
