{{--
    Datenschutz und Löschung im Konto.

    Vorgabe des Masterprompts, Abschnitt 8.2 und 19: Der Nutzer kann seinen
    Datenexport anfordern, die Löschung seines Kontos beantragen und innerhalb
    der Frist zurücknehmen. Zusätzlich erhält er Auskunft, welche Daten
    dauerhaft gespeichert werden und welche nicht, sowie den Hinweis auf seine
    eigene Aufbewahrungspflicht.

    Die Seite enthält keine Rechtsberatung und keine Garantieaussagen.

    Gestaltung nach docs/designsystem.md: Seitenkopf (4.3), Karten (4.5),
    Listen in Karten (4.8), Leerzustand (4.11), Formular mit x-hvm.field (4.6),
    destruktive Handlung als variant="danger" (4.12).
--}}
@extends('layouts.portal')

@section('titel', 'Datenschutz und Löschung')

@section('content')
    <x-hvm.page-header
        eyebrow="Konto"
        title="Datenschutz und Löschung"
        lead="Hier fordern Sie Ihren Datenexport an, sehen ein, welche Daten dauerhaft gespeichert werden, und beantragen bei Bedarf die Löschung Ihres Kontos." />

    {{-- Hinweis auf die eigene Aufbewahrungspflicht ---------------------------- --}}

    <div class="mt-8">
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

    <section class="mt-16" aria-labelledby="ueberschrift-export">
        <p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">Auskunft</p>
        <h2 id="ueberschrift-export" class="mt-1 text-2xl font-semibold tracking-tight text-hvm-textschwarz">Datenexport anfordern</h2>

        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-12">
            <x-hvm.card class="min-w-0 lg:col-span-5" title="Datenexport anfordern" eyebrow="ZIP-Paket" accent>
                <p class="text-sm leading-relaxed text-hvm-textschwarz">
                    Sie erhalten alle Daten Ihres Kontos als ZIP-Paket: je Entität eine Datei in
                    maschinenlesbarer Form, eine lesbare Übersicht als Textdatei sowie Ihre erzeugten
                    Abrechnungs-PDFs und die Rechnungen der Hausverwaltung Müller GmbH.
                </p>
                <p class="mt-3 text-sm leading-relaxed text-hvm-text-sekundaer">
                    Das Paket enthält keine Ihrer hochgeladenen Originaldateien. Diese werden nur zur
                    Auswertung kurzfristig verarbeitet und danach automatisch gelöscht. Der Download ist
                    nur angemeldet und über Ihr Konto möglich.
                </p>

                <form method="POST" action="{{ route('portal.datenschutz.export') }}" class="mt-6">
                    @csrf
                    <x-hvm.button type="submit" variant="primary">
                        <x-hvm.icon name="document" class="h-4 w-4" />
                        Datenexport erstellen
                    </x-hvm.button>
                </form>
            </x-hvm.card>

            <div class="min-w-0 lg:col-span-7">
                <x-hvm.card padding="none" class="h-full">
                    <div class="p-6 sm:p-7 {{ $exporte === [] ? '' : 'pb-0 sm:pb-0' }}">
                        <h3 class="text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl">Bereitstehende Datenexporte</h3>

                        @if ($exporte === [])
                            <x-hvm.empty-state class="mt-4" icon="inbox" title="Noch kein Export">
                                <p>Es steht derzeit kein Datenexport bereit.</p>
                            </x-hvm.empty-state>
                        @endif
                    </div>

                    @if ($exporte !== [])
                        <ul class="mt-5 divide-y divide-hvm-linie border-t border-hvm-linie">
                            @foreach ($exporte as $export)
                                <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 sm:px-6">
                                    <span class="flex min-w-0 items-center gap-3 text-sm text-hvm-textschwarz">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-hvm-canvas-deep text-hvm-text-sekundaer" aria-hidden="true">
                                            <x-hvm.icon name="layers" class="h-4 w-4" />
                                        </span>
                                        <span class="min-w-0">
                                            Erstellt am
                                            <span class="font-semibold tabular">{{ $export->generated_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</span>
                                            @if ($export->byte_size !== null)
                                                , <span class="tabular">{{ number_format((int) $export->byte_size / 1024, 0, ',', '.') }} KB</span>
                                            @endif
                                        </span>
                                    </span>
                                    <span class="flex flex-wrap gap-2">
                                        <x-hvm.button href="{{ route('portal.datenschutz.export.download', ['export' => $export->getKey()]) }}"
                                                      variant="secondary" size="sm">
                                            Herunterladen
                                        </x-hvm.button>
                                        <form method="POST"
                                              action="{{ route('portal.datenschutz.export.link', ['export' => $export->getKey()]) }}">
                                            @csrf
                                            <x-hvm.button type="submit" variant="ghost" size="sm">
                                                Kurzlebigen Link erzeugen
                                            </x-hvm.button>
                                        </form>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-hvm.card>
            </div>
        </div>
    </section>

    {{-- Auskunft zur Speicherung ------------------------------------------------ --}}

    <section class="mt-16" aria-labelledby="ueberschrift-speicherung">
        <p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">Transparenz</p>
        <h2 id="ueberschrift-speicherung" class="mt-1 text-2xl font-semibold tracking-tight text-hvm-textschwarz">Speicherung Ihrer Daten</h2>

        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
            <x-hvm.card class="min-w-0" title="Was dauerhaft gespeichert wird">
                <ul class="space-y-3 text-sm leading-relaxed text-hvm-textschwarz">
                    @foreach ($dauerhaft as $punkt)
                        <li class="flex gap-3">
                            <x-hvm.icon name="check" class="mt-0.5 h-5 w-5 shrink-0 text-hvm-orange-dark" />
                            <span class="min-w-0">{{ $punkt }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-hvm.card>

            <x-hvm.card class="min-w-0" title="Was nicht dauerhaft gespeichert wird">
                <ul class="space-y-3 text-sm leading-relaxed text-hvm-textschwarz">
                    @foreach ($nichtDauerhaft as $punkt)
                        <li class="flex gap-3">
                            <x-hvm.icon name="x" class="mt-0.5 h-5 w-5 shrink-0 text-hvm-text-sekundaer" />
                            <span class="min-w-0">{{ $punkt }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-5 rounded-2xl bg-hvm-canvas p-4 text-sm leading-relaxed text-hvm-text-sekundaer">
                    Verbindlich sind logische Löschung, Ausschluss aus allen Backups, eine kurze
                    Aufbewahrung im Verarbeitungsbereich und ein dokumentierter Löschstatus.
                </p>
            </x-hvm.card>
        </div>
    </section>

    {{-- Löschung des Kontos ----------------------------------------------------- --}}

    <section class="mt-16" aria-labelledby="ueberschrift-loeschung">
        <p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">Kontoende</p>
        <h2 id="ueberschrift-loeschung" class="mt-1 text-2xl font-semibold tracking-tight text-hvm-textschwarz">Löschung des Kontos</h2>

        <x-hvm.card class="mt-6 rounded-3xl" :kennlinie="true" padding="none">
            <div class="p-6 sm:p-8">
                <p class="max-w-prose text-sm leading-relaxed text-hvm-textschwarz">{{ $verfahrenshinweis }}</p>

                <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <div class="min-w-0 rounded-2xl bg-hvm-canvas p-4 sm:p-5">
                        <h3 class="text-sm font-semibold text-hvm-textschwarz">Das wird gelöscht</h3>
                        <ul class="mt-3 space-y-2 text-sm leading-relaxed text-hvm-textschwarz">
                            @foreach ($geloescht as $punkt)
                                <li class="flex gap-3">
                                    <x-hvm.icon name="trash" class="mt-0.5 h-4 w-4 shrink-0 text-hvm-text-sekundaer" />
                                    <span class="min-w-0">{{ $punkt }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="min-w-0 rounded-2xl bg-hvm-canvas p-4 sm:p-5">
                        <h3 class="text-sm font-semibold text-hvm-textschwarz">Das bleibt erhalten</h3>
                        <ul class="mt-3 space-y-2 text-sm leading-relaxed text-hvm-textschwarz">
                            @foreach ($erhalten as $punkt)
                                <li class="flex gap-3">
                                    <x-hvm.icon name="lock" class="mt-0.5 h-4 w-4 shrink-0 text-hvm-text-sekundaer" />
                                    <span class="min-w-0">{{ $punkt }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>

                @if ($zustand->pending)
                    <form method="POST" action="{{ route('portal.datenschutz.loeschung.zuruecknehmen') }}"
                          class="mt-8 border-t border-hvm-linie pt-6">
                        @csrf
                        @method('DELETE')
                        <x-hvm.button type="submit" variant="secondary" size="lg">Löschantrag zurücknehmen</x-hvm.button>
                    </form>
                @else
                    <form method="POST" action="{{ route('portal.datenschutz.loeschung') }}" class="mt-8 space-y-6 border-t border-hvm-linie pt-6">
                        @csrf

                        <x-hvm.field
                            name="bestaetigung"
                            id="bestaetigung"
                            type="checkbox"
                            value="1"
                            label="Ich beantrage die Löschung meines Kontos. Ich habe zur Kenntnis genommen, dass meine Daten nach Ablauf der Frist von {{ $fristTage }} Tagen endgültig gelöscht werden und dass die Rechnungen der Hausverwaltung Müller GmbH aus Aufbewahrungsgründen erhalten bleiben." />

                        <div class="max-w-md">
                            <x-hvm.field
                                id="loeschung-passwort"
                                name="current_password"
                                label="Aktuelles Passwort zur Bestätigung"
                                type="password"
                                autocomplete="current-password"
                                hint="Wir senden Ihnen eine Bestätigung des Antrags mit dem Löschtermin per E-Mail."
                                :required="true" />
                        </div>

                        <div class="flex flex-wrap gap-3">
                            <x-hvm.button type="submit" variant="danger" size="lg">
                                <x-hvm.icon name="trash" class="h-5 w-5" />
                                Löschung beantragen
                            </x-hvm.button>
                        </div>
                    </form>
                @endif
            </div>
        </x-hvm.card>
    </section>
@endsection
