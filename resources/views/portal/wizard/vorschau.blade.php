@extends('layouts.portal')

@section('titel', 'Vorschau')

@section('content')
    <x-hvm.section-heading
        title="Schritt 10: Vorschau und Bestätigung"
        lead="Alle Mieterabrechnungen und die Eigentümerübersicht werden serverseitig erzeugt. Jede Seite trägt ein Wasserzeichen." />

    <div class="mt-6">
        @include('portal.wizard.partials.fortschritt', [
            'fortschritt' => $fortschritt,
            'billingRun' => $billingRun,
            'wiedereinstieg' => $wiedereinstieg,
        ])
    </div>

    <x-hvm.card class="mt-6" title="Unverbindliche Preisschätzung">
        <p>{{ $schaetzung->explanation() }}</p>
        <p class="mt-2 font-semibold">Voraussichtlich {{ $schaetzung->totalGross->format() }} brutto</p>
        <p class="mt-2 text-sm text-hvm-anthrazit">{{ $schaetzung->hint() }}</p>
    </x-hvm.card>

    @if ($sperrgrund !== null)
        <x-hvm.alert variant="error" class="mt-6" label="Blockiert die Abrechnung">
            <p>{{ $sperrgrund }}</p>
        </x-hvm.alert>
    @endif

    <form method="POST" class="mt-6"
          action="{{ route('portal.wizard.vorschau.erzeugen', ['billingRun' => $billingRun->getKey()]) }}">
        @csrf
        <x-hvm.button type="submit" variant="primary">
            {{ $gueltig ? 'Vorschau neu erzeugen' : 'Vorschau erzeugen' }}
        </x-hvm.button>
    </form>

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

        <div class="mt-6 space-y-6">
            @foreach ($dokumente as $dokument)
                <x-hvm.card :title="$dokument->titel">
                    <p class="text-sm text-hvm-anthrazit">
                        {{ $dokument->untertitel }} {{ $dokument->seiten }} Seiten.
                    </p>

                    <div class="mt-3 h-[600px] overflow-auto rounded border border-hvm-hellgrau bg-hvm-umrissgrau">
                        <object class="h-full w-full"
                                type="application/pdf"
                                data="{{ route('portal.downloads.stream', ['generatedDocument' => $dokument->id()]) }}"
                                aria-label="{{ $dokument->titel }} als Vorschau">
                            <p class="p-4 text-sm">
                                Ihr Browser kann die Vorschau nicht direkt anzeigen. Sie können das Dokument mit
                                Wasserzeichen öffnen.
                            </p>
                        </object>
                    </div>

                    <p class="mt-3 text-sm">
                        <a class="underline underline-offset-2"
                           href="{{ route('portal.downloads.stream', ['generatedDocument' => $dokument->id()]) }}">
                            Dokument mit Wasserzeichen öffnen
                        </a>
                    </p>
                </x-hvm.card>
            @endforeach
        </div>

        <x-hvm.card class="mt-6" title="Ihre Bestätigung vor der Zahlung">
            @if ($bestaetigt)
                <x-hvm.alert variant="success" label="Erledigt">
                    <p>Ihre Bestätigung ist protokolliert. Sie können zur Zahlung fortfahren.</p>
                </x-hvm.alert>
            @else
                <form method="POST"
                      action="{{ route('portal.wizard.vorschau.bestaetigen', ['billingRun' => $billingRun->getKey()]) }}">
                    @csrf

                    <label class="flex items-start gap-3">
                        <input type="checkbox" name="bestaetigung" value="1" class="mt-1" required>
                        <span>{{ $bestaetigungstext }}</span>
                    </label>

                    <p class="mt-3 text-sm text-hvm-anthrazit">
                        Textfassung {{ $textversion }}. Wir protokollieren Zweck, Zeitpunkt, gekürzte IP-Adresse und
                        einen Hashwert Ihres Browserkennzeichens. VOR LIVEGANG DURCH RECHTSANWALT PRÜFEN UND
                        FREIGEBEN.
                    </p>

                    <x-hvm.button type="submit" variant="primary" class="mt-4">
                        Bestätigen und zur Zahlung fortfahren
                    </x-hvm.button>
                </form>
            @endif
        </x-hvm.card>
    @endif
@endsection
