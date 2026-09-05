{{--
    Schritt 12: Downloadbereich (Masterprompt 9 Schritt 12)

    Ausgeliefert werden ausschliesslich finale, wasserzeichenfreie Artefakte
    ueber die bestehenden autorisierten Downloadrouten. Ersetzte Versionen
    bleiben erhalten und werden gesondert als Historie ausgewiesen
    (Abschnitt 11.5).

    Gestaltung nach docs/designsystem.md: Seitenkopf (4.3), Karten (4.5),
    Listen in Karten (4.8), Leerzustand (4.11), ein Primaerbutton (4.12).
--}}
@extends('layouts.portal')

@section('titel', 'Abschluss Abrechnung '.$lauf->billing_year)

@php
    $downloadRoute = static fn ($dokument) => route('portal.downloads.stream', ['generatedDocument' => $dokument->getKey()]);
@endphp

@section('content')
    <x-hvm.page-header
        eyebrow="Schritt 12 von 12"
        title="Ihre Abrechnung ist fertig"
        lead="Alle Dateien stehen dauerhaft in Ihrem Konto bereit.">
        <x-hvm.badge variant="success">Erledigt</x-hvm.badge>
    </x-hvm.page-header>

    <div class="mt-10 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-hvm.card class="min-w-0 lg:col-span-2" title="Sammeldownload" eyebrow="Alle Dateien" accent>
            @forelse ($pakete as $paket)
                <div class="flex gap-3">
                    <span class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark" aria-hidden="true">
                        <x-hvm.icon name="layers" class="h-5 w-5" />
                    </span>
                    <p class="min-w-0 text-sm leading-relaxed text-hvm-textschwarz">
                        Alle finalen Dateien in einem ZIP-Paket, {{ $paket->page_count }} Dateien.
                    </p>
                </div>
                <div class="mt-5">
                    <x-hvm.button href="{{ $downloadRoute($paket) }}" variant="primary">
                        ZIP-Paket herunterladen
                    </x-hvm.button>
                </div>
            @empty
                <p class="text-sm leading-relaxed text-hvm-text-sekundaer">Für diesen Lauf liegt kein ZIP-Paket vor.</p>
            @endforelse
        </x-hvm.card>

        <x-hvm.card class="min-w-0" title="Gesamtübersicht" eyebrow="Für Sie als Vermieter">
            @forelse ($uebersichten as $uebersicht)
                <div class="{{ $loop->first ? '' : 'mt-3' }}">
                    <x-hvm.button href="{{ $downloadRoute($uebersicht) }}" variant="secondary" size="sm">
                        <x-hvm.icon name="list" class="h-4 w-4" />
                        Eigentümerübersicht herunterladen
                    </x-hvm.button>
                </div>
            @empty
                <p class="text-sm leading-relaxed text-hvm-text-sekundaer">Für diesen Lauf liegt keine Gesamtübersicht vor.</p>
            @endforelse
        </x-hvm.card>

        <x-hvm.card class="min-w-0" title="Rechnung" eyebrow="Hausverwaltung Müller GmbH">
            @forelse ($rechnungsdaten as $rechnung)
                <div class="border-b border-hvm-linie py-3 first:pt-0 last:border-0 last:pb-0">
                    <p class="text-sm font-semibold text-hvm-textschwarz tabular">{{ $rechnung->number }}</p>
                    <p class="mt-1 text-sm leading-relaxed text-hvm-text-sekundaer">
                        {{ $rechnung->issued_on?->format('d.m.Y') }},
                        <span class="whitespace-nowrap tabular">{{ number_format($rechnung->gross_cent / 100, 2, ',', '.') }} EUR</span> brutto,
                        {{ $rechnung->status->label() }}
                    </p>
                </div>
            @empty
                <p class="text-sm leading-relaxed text-hvm-text-sekundaer">
                    Die Rechnung wird bereitgestellt, sobald sie erzeugt ist.
                </p>
            @endforelse

            @foreach ($rechnungen as $beleg)
                <div class="mt-4">
                    <x-hvm.button href="{{ $downloadRoute($beleg) }}" variant="secondary" size="sm">
                        <x-hvm.icon name="receipt" class="h-4 w-4" />
                        Rechnung als PDF
                    </x-hvm.button>
                </div>
            @endforeach
        </x-hvm.card>
    </div>

    {{-- Mieterabrechnungen ---------------------------------------------------- --}}

    <section class="mt-16" aria-labelledby="ueberschrift-mieterabrechnungen">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">Einzeldateien</p>
                <h2 id="ueberschrift-mieterabrechnungen" class="mt-1 text-2xl font-semibold tracking-tight text-hvm-textschwarz">Mieterabrechnungen</h2>
            </div>
            @if ($abrechnungen !== [])
                <x-hvm.badge variant="neutral">{{ count($abrechnungen) }} {{ count($abrechnungen) === 1 ? 'Datei' : 'Dateien' }}</x-hvm.badge>
            @endif
        </div>

        @if ($abrechnungen === [])
            <x-hvm.empty-state class="mt-6" icon="document" title="Keine finale Mieterabrechnung">
                <p>Für diesen Lauf liegt keine finale Mieterabrechnung vor.</p>
            </x-hvm.empty-state>
        @else
            <x-hvm.card class="mt-6" padding="none">
                <ul class="divide-y divide-hvm-linie">
                    @foreach ($abrechnungen as $index => $abrechnung)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 sm:px-6">
                            <span class="flex min-w-0 items-center gap-3 text-sm text-hvm-textschwarz">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-hvm-canvas-deep text-hvm-text-sekundaer" aria-hidden="true">
                                    <x-hvm.icon name="document" class="h-4 w-4" />
                                </span>
                                <span class="min-w-0">
                                    <span class="font-semibold">Mieterabrechnung {{ $index + 1 }},</span>
                                    {{ $abrechnung->page_count }} {{ $abrechnung->page_count === 1 ? 'Seite' : 'Seiten' }}
                                </span>
                            </span>
                            <x-hvm.button href="{{ $downloadRoute($abrechnung) }}" variant="secondary" size="sm">
                                Herunterladen
                            </x-hvm.button>
                        </li>
                    @endforeach
                </ul>
            </x-hvm.card>
        @endif
    </section>

    @if ($anlagen !== [])
        <section class="mt-10" aria-labelledby="ueberschrift-anlagen">
            <p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">Steuerliche Anlagen</p>
            <h2 id="ueberschrift-anlagen" class="mt-1 text-2xl font-semibold tracking-tight text-hvm-textschwarz">Anlagen nach Paragraf 35a EStG</h2>

            <x-hvm.card class="mt-6" padding="none">
                <ul class="divide-y divide-hvm-linie">
                    @foreach ($anlagen as $index => $anlage)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 sm:px-6">
                            <span class="flex min-w-0 items-center gap-3 text-sm text-hvm-textschwarz">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-hvm-canvas-deep text-hvm-text-sekundaer" aria-hidden="true">
                                    <x-hvm.icon name="document" class="h-4 w-4" />
                                </span>
                                <span class="font-semibold">Anlage {{ $index + 1 }}</span>
                            </span>
                            <x-hvm.button href="{{ $downloadRoute($anlage) }}" variant="secondary" size="sm">
                                Herunterladen
                            </x-hvm.button>
                        </li>
                    @endforeach
                </ul>
            </x-hvm.card>
        </section>
    @endif

    {{-- Hinweise -------------------------------------------------------------- --}}

    <div class="mt-16 grid grid-cols-1 gap-6 {{ $ersetzt !== [] ? 'lg:grid-cols-2' : '' }}">
        @if ($ersetzt !== [])
            <x-hvm.card class="min-w-0" title="Frühere Versionen" eyebrow="Historie" tone="canvas">
                <p class="text-sm leading-relaxed text-hvm-textschwarz">
                    Eine Korrektur erzeugt immer eine neue Version. Die früheren Dateien werden nicht überschrieben und
                    bleiben mit ihrem Nachweis erhalten. Ausgeliefert wird ausschließlich die aktuelle Version, damit
                    keine überholte Abrechnung versehentlich an einen Mieter geht.
                </p>
                <ul class="mt-4 divide-y divide-hvm-linie">
                    @foreach ($ersetzt as $alt)
                        <li class="flex gap-3 py-3 text-sm leading-relaxed text-hvm-text-sekundaer">
                            <x-hvm.icon name="clock" class="mt-0.5 h-4 w-4 shrink-0" />
                            <span>
                                {{ $alt->kind->label() }}, ersetzt, erstellt am
                                {{ $alt->generated_at?->format('d.m.Y') }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </x-hvm.card>
        @endif

        <x-hvm.card class="min-w-0 {{ $ersetzt !== [] ? '' : 'max-w-3xl' }}" title="Korrektur nach Zahlung" eyebrow="Gut zu wissen" tone="canvas">
            <p class="text-sm leading-relaxed text-hvm-textschwarz">
                Ein abgeschlossener Abrechnungslauf wird nicht mehr verändert. Stellen Sie nach der Zahlung einen Fehler
                fest, legen Sie bitte einen neuen Abrechnungslauf für dasselbe Objekt und denselben Zeitraum an. Er wird
                regulär berechnet und bezahlt; die hier bereitgestellten Dateien bleiben unverändert erhalten.
            </p>
        </x-hvm.card>
    </div>

    <div class="mt-10 flex max-w-prose gap-3">
        <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark" aria-hidden="true">
            <x-hvm.icon name="info" class="h-4 w-4" />
        </span>
        <p class="min-w-0 text-sm leading-relaxed text-hvm-text-sekundaer">
            Bitte bewahren Sie Ihre Originalrechnungen, Bescheide und Mietverträge selbst auf. Sie werden für eine
            mögliche Belegeinsicht Ihrer Mieter benötigt und liegen nicht in diesem Konto.
        </p>
    </div>
@endsection
