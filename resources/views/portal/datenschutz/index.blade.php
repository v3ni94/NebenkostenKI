{{--
    Datenschutz und Löschung im Konto.

    Vorgabe des Masterprompts, Abschnitt 8.2 und 19: Der Nutzer kann seinen
    Datenexport anfordern, die Löschung seines Kontos beantragen und innerhalb
    der Frist zurücknehmen. Zusätzlich erhält er Auskunft, welche Daten
    dauerhaft gespeichert werden und welche nicht, sowie den Hinweis auf seine
    eigene Aufbewahrungspflicht.

    Die Seite enthält keine Rechtsberatung und keine Garantieaussagen.
--}}
@extends('layouts.portal')

@section('titel', 'Datenschutz und Löschung')

@section('content')
    <x-hvm.section-heading
        title="Datenschutz und Löschung"
        lead="Hier fordern Sie Ihren Datenexport an, sehen ein, welche Daten dauerhaft gespeichert werden, und beantragen bei Bedarf die Löschung Ihres Kontos." />

    {{-- Hinweis auf die eigene Aufbewahrungspflicht ---------------------------- --}}

    <div class="mt-6">
        <x-hvm.alert variant="warning" label="Bitte beachten" title="Bewahren Sie Ihre Originalbelege selbst auf">
            {{ $eigenverwahrung }}
        </x-hvm.alert>
    </div>

    {{-- Laufender Löschantrag --------------------------------------------------- --}}

    @if ($zustand->pending)
        <div class="mt-6">
            <x-hvm.alert variant="warning" label="Löschantrag läuft"
                         title="Ihr Konto wird am {{ $zustand->dueAtLabel() }} gelöscht">
                Antrag vom {{ $zustand->requestedAtLabel() }}. Verbleibende Zeit:
                {{ $zustand->remainingDays() }} Tage. Bis zum Ablauf der Frist bleibt Ihr Konto
                uneingeschränkt nutzbar, danach ist die Löschung nicht mehr rückholbar.
            </x-hvm.alert>
        </div>
    @endif

    {{-- Datenexport ------------------------------------------------------------- --}}

    <div class="mt-8">
        <x-hvm.card title="Datenexport anfordern">
            <p class="text-sm text-hvm-textschwarz">
                Sie erhalten alle Daten Ihres Kontos als ZIP-Paket: je Entität eine Datei in
                maschinenlesbarer Form, eine lesbare Übersicht als Textdatei sowie Ihre erzeugten
                Abrechnungs-PDFs und die Rechnungen der Hausverwaltung Müller GmbH.
            </p>
            <p class="mt-2 text-sm text-hvm-textschwarz">
                Das Paket enthält keine Ihrer hochgeladenen Originaldateien. Diese werden nur zur
                Auswertung kurzfristig verarbeitet und danach automatisch gelöscht. Der Download ist
                nur angemeldet und über Ihr Konto möglich.
            </p>

            <form method="POST" action="{{ route('portal.datenschutz.export') }}" class="mt-4">
                @csrf
                <x-hvm.button type="submit">Datenexport erstellen</x-hvm.button>
            </form>
        </x-hvm.card>
    </div>

    {{-- Bereitstehende Exporte -------------------------------------------------- --}}

    <div class="mt-6">
        <x-hvm.card title="Bereitstehende Datenexporte">
            @if ($exporte === [])
                <p class="text-sm text-hvm-dunkelgrau">
                    Es steht derzeit kein Datenexport bereit.
                </p>
            @else
                <ul class="divide-y divide-hvm-mittelgrau">
                    @foreach ($exporte as $export)
                        <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                            <span class="text-sm text-hvm-textschwarz">
                                Erstellt am
                                {{ $export->generated_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') }}
                                @if ($export->byte_size !== null)
                                    , {{ number_format((int) $export->byte_size / 1024, 0, ',', '.') }} KB
                                @endif
                            </span>
                            <span class="flex flex-wrap gap-3">
                                <a class="text-sm font-medium underline underline-offset-2"
                                   href="{{ route('portal.datenschutz.export.download', ['export' => $export->getKey()]) }}">
                                    Herunterladen
                                </a>
                                <form method="POST"
                                      action="{{ route('portal.datenschutz.export.link', ['export' => $export->getKey()]) }}">
                                    @csrf
                                    <button type="submit" class="text-sm font-medium underline underline-offset-2">
                                        Kurzlebigen Link erzeugen
                                    </button>
                                </form>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-hvm.card>
    </div>

    {{-- Auskunft zur Speicherung ------------------------------------------------ --}}

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <x-hvm.card title="Was dauerhaft gespeichert wird">
            <ul class="list-disc space-y-2 pl-5 text-sm text-hvm-textschwarz">
                @foreach ($dauerhaft as $punkt)
                    <li>{{ $punkt }}</li>
                @endforeach
            </ul>
        </x-hvm.card>

        <x-hvm.card title="Was nicht dauerhaft gespeichert wird">
            <ul class="list-disc space-y-2 pl-5 text-sm text-hvm-textschwarz">
                @foreach ($nichtDauerhaft as $punkt)
                    <li>{{ $punkt }}</li>
                @endforeach
            </ul>
            <p class="mt-3 text-sm text-hvm-dunkelgrau">
                Verbindlich sind logische Löschung, Ausschluss aus allen Backups, eine kurze
                Aufbewahrung im Verarbeitungsbereich und ein dokumentierter Löschstatus.
            </p>
        </x-hvm.card>
    </div>

    {{-- Löschung des Kontos ----------------------------------------------------- --}}

    <div class="mt-6">
        <x-hvm.card title="Löschung des Kontos">
            <p class="text-sm text-hvm-textschwarz">{{ $verfahrenshinweis }}</p>

            <div class="mt-5 grid gap-6 lg:grid-cols-2">
                <div>
                    <h3 class="text-sm font-semibold text-hvm-textschwarz">Das wird gelöscht</h3>
                    <ul class="mt-2 list-disc space-y-2 pl-5 text-sm text-hvm-textschwarz">
                        @foreach ($geloescht as $punkt)
                            <li>{{ $punkt }}</li>
                        @endforeach
                    </ul>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-hvm-textschwarz">Das bleibt erhalten</h3>
                    <ul class="mt-2 list-disc space-y-2 pl-5 text-sm text-hvm-textschwarz">
                        @foreach ($erhalten as $punkt)
                            <li>{{ $punkt }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>

            @if ($zustand->pending)
                <form method="POST" action="{{ route('portal.datenschutz.loeschung.zuruecknehmen') }}"
                      class="mt-6">
                    @csrf
                    @method('DELETE')
                    <x-hvm.button type="submit">Löschantrag zurücknehmen</x-hvm.button>
                </form>
            @else
                <form method="POST" action="{{ route('portal.datenschutz.loeschung') }}" class="mt-6 space-y-4">
                    @csrf

                    <label for="bestaetigung" class="flex items-start gap-3 text-sm text-hvm-textschwarz">
                        <input id="bestaetigung" name="bestaetigung" type="checkbox" value="1"
                               class="mt-1 h-5 w-5 rounded border border-hvm-mittelgrau">
                        <span>
                            Ich beantrage die Löschung meines Kontos. Ich habe zur Kenntnis genommen, dass
                            meine Daten nach Ablauf der Frist von {{ $fristTage }} Tagen endgültig gelöscht
                            werden und dass die Rechnungen der Hausverwaltung Müller GmbH aus
                            Aufbewahrungsgründen erhalten bleiben.
                        </span>
                    </label>
                    @error('bestaetigung')
                        <p class="text-sm text-status-error">{{ $message }}</p>
                    @enderror

                    <x-hvm.button type="submit">Löschung beantragen</x-hvm.button>
                </form>
            @endif
        </x-hvm.card>
    </div>
@endsection
