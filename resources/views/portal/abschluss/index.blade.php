{{--
    Schritt 12: Downloadbereich (Masterprompt 9 Schritt 12)

    Ausgeliefert werden ausschliesslich finale, wasserzeichenfreie Artefakte
    ueber die bestehenden autorisierten Downloadrouten. Ersetzte Versionen
    bleiben erhalten und werden gesondert als Historie ausgewiesen
    (Abschnitt 11.5).
--}}
@extends('layouts.portal')

@section('titel', 'Abschluss Abrechnung '.$lauf->billing_year)

@php
    $downloadRoute = static fn ($dokument) => route('portal.downloads.stream', ['generatedDocument' => $dokument->getKey()]);
@endphp

@section('content')
    <x-hvm.section-heading
        eyebrow="Schritt 12 von 12"
        title="Ihre Abrechnung ist fertig"
        lead="Alle Dateien stehen dauerhaft in Ihrem Konto bereit." />

    <div class="mt-8 grid gap-6 lg:grid-cols-3">
        <x-hvm.card title="Sammeldownload" accent class="lg:col-span-1">
            @forelse ($pakete as $paket)
                <p class="text-sm text-hvm-textschwarz">
                    Alle finalen Dateien in einem ZIP-Paket, {{ $paket->page_count }} Dateien.
                </p>
                <div class="mt-4">
                    <x-hvm.button href="{{ $downloadRoute($paket) }}" variant="primary">
                        ZIP-Paket herunterladen
                    </x-hvm.button>
                </div>
            @empty
                <p class="text-sm text-hvm-anthrazit">Für diesen Lauf liegt kein ZIP-Paket vor.</p>
            @endforelse
        </x-hvm.card>

        <x-hvm.card title="Gesamtübersicht" class="lg:col-span-1">
            @forelse ($uebersichten as $uebersicht)
                <div class="mt-2">
                    <x-hvm.button href="{{ $downloadRoute($uebersicht) }}" variant="secondary" size="sm">
                        Eigentümerübersicht herunterladen
                    </x-hvm.button>
                </div>
            @empty
                <p class="text-sm text-hvm-anthrazit">Für diesen Lauf liegt keine Gesamtübersicht vor.</p>
            @endforelse
        </x-hvm.card>

        <x-hvm.card title="Rechnung" class="lg:col-span-1">
            @forelse ($rechnungsdaten as $rechnung)
                <div class="border-b border-hvm-hellgrau py-2 last:border-0">
                    <p class="text-sm font-semibold text-hvm-anthrazit">{{ $rechnung->number }}</p>
                    <p class="text-sm text-hvm-textschwarz">
                        {{ $rechnung->issued_on?->format('d.m.Y') }},
                        {{ number_format($rechnung->gross_cent / 100, 2, ',', '.') }} EUR brutto,
                        {{ $rechnung->status->label() }}
                    </p>
                </div>
            @empty
                <p class="text-sm text-hvm-anthrazit">
                    Die Rechnung wird bereitgestellt, sobald sie erzeugt ist.
                </p>
            @endforelse

            @foreach ($rechnungen as $beleg)
                <div class="mt-3">
                    <x-hvm.button href="{{ $downloadRoute($beleg) }}" variant="secondary" size="sm">
                        Rechnung als PDF
                    </x-hvm.button>
                </div>
            @endforeach
        </x-hvm.card>
    </div>

    <x-hvm.card class="mt-8" title="Mieterabrechnungen">
        @if ($abrechnungen === [])
            <p class="text-sm text-hvm-anthrazit">Für diesen Lauf liegt keine finale Mieterabrechnung vor.</p>
        @else
            <ul class="divide-y divide-hvm-hellgrau">
                @foreach ($abrechnungen as $index => $abrechnung)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <span class="text-sm text-hvm-textschwarz">
                            Mieterabrechnung {{ $index + 1 }},
                            {{ $abrechnung->page_count }} {{ $abrechnung->page_count === 1 ? 'Seite' : 'Seiten' }}
                        </span>
                        <x-hvm.button href="{{ $downloadRoute($abrechnung) }}" variant="secondary" size="sm">
                            Herunterladen
                        </x-hvm.button>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-hvm.card>

    @if ($anlagen !== [])
        <x-hvm.card class="mt-6" title="Anlagen nach Paragraf 35a EStG">
            <ul class="divide-y divide-hvm-hellgrau">
                @foreach ($anlagen as $index => $anlage)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <span class="text-sm text-hvm-textschwarz">Anlage {{ $index + 1 }}</span>
                        <x-hvm.button href="{{ $downloadRoute($anlage) }}" variant="secondary" size="sm">
                            Herunterladen
                        </x-hvm.button>
                    </li>
                @endforeach
            </ul>
        </x-hvm.card>
    @endif

    @if ($ersetzt !== [])
        <x-hvm.card class="mt-6" title="Frühere Versionen">
            <p class="text-sm text-hvm-textschwarz">
                Eine Korrektur erzeugt immer eine neue Version. Die früheren Dateien werden nicht überschrieben und
                bleiben mit ihrem Nachweis erhalten. Ausgeliefert wird ausschließlich die aktuelle Version, damit
                keine überholte Abrechnung versehentlich an einen Mieter geht.
            </p>
            <ul class="mt-3 divide-y divide-hvm-hellgrau">
                @foreach ($ersetzt as $alt)
                    <li class="py-3 text-sm text-hvm-anthrazit">
                        {{ $alt->kind->label() }}, ersetzt, erstellt am
                        {{ $alt->generated_at?->format('d.m.Y') }}
                    </li>
                @endforeach
            </ul>
        </x-hvm.card>
    @endif

    <x-hvm.card class="mt-6" title="Korrektur nach Zahlung">
        <p class="text-sm text-hvm-textschwarz">
            Ein abgeschlossener Abrechnungslauf wird nicht mehr verändert. Stellen Sie nach der Zahlung einen Fehler
            fest, legen Sie bitte einen neuen Abrechnungslauf für dasselbe Objekt und denselben Zeitraum an. Er wird
            regulär berechnet und bezahlt; die hier bereitgestellten Dateien bleiben unverändert erhalten.
        </p>
    </x-hvm.card>

    <p class="mt-8 text-sm text-hvm-anthrazit">
        Bitte bewahren Sie Ihre Originalrechnungen, Bescheide und Mietverträge selbst auf. Sie werden für eine
        mögliche Belegeinsicht Ihrer Mieter benötigt und liegen nicht in diesem Konto.
    </p>
@endsection
