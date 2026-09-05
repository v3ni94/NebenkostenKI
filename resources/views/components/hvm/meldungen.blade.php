{{--
    Meldungsblock der Anwendung (Designsystem 4.14).

    Rendert die Statusmeldung nach dem Speichern (session('status'),
    role="status"), den Hinweis (session('hinweis')) und die Sammelmeldung
    der Formularfehler ($errors, role="alert", fokussierbar, Eintraege als
    Ankerlinks auf die Felder). Die Layouts rendern denselben Block als
    Rueckfall vor dem Inhalt; sobald eine Seite den Block selbst platziert
    (x-hvm.page-header tut das direkt unter dem Seitenkopf), merkt sich die
    Komponente das ueber eine geteilte View-Variable und das Layout laesst
    seinen Block aus. So steht die Meldung unter der Ueberschrift statt davor,
    ohne dass jede Seite etwas dafuer tun muss.

    Anker: Der Feldschluessel wird wie in x-hvm.field zur Element-ID
    (Sonderzeichen werden zu Bindestrichen). Felder mit eigener ID werden nicht
    getroffen; der Link bleibt dann ohne Ziel, die Meldung ist trotzdem lesbar.

    Props:
      titel   Ueberschrift der Fehlerliste, Standard "Ihre Eingabe konnte
              nicht gespeichert werden"
--}}
@props([
    'titel' => 'Ihre Eingabe konnte nicht gespeichert werden',
])

@php
    $status = session('status');
    $hinweis = session('hinweis');
    $fehlerbeutel = $errors ?? view()->shared('errors');
    $hatFehler = $fehlerbeutel instanceof \Illuminate\Support\ViewErrorBag && $fehlerbeutel->any();
    $sichtbar = $status || $hinweis || $hatFehler;

    view()->share('hvmMeldungenGerendert', true);

    $feldId = static fn (string $schluessel): string => preg_replace('/[^A-Za-z0-9_-]/', '-', $schluessel) ?? $schluessel;
@endphp

@if ($sichtbar)
    <div {{ $attributes->class('space-y-4') }}>
        @if ($status)
            <x-hvm.alert variant="success" label="Erledigt" role="status">
                {{ $status }}
            </x-hvm.alert>
        @endif

        @if ($hinweis)
            <x-hvm.alert variant="info" label="Hinweis" role="status">
                {{ $hinweis }}
            </x-hvm.alert>
        @endif

        @if ($hatFehler)
            {{-- Fokus auf die Fehlerliste, damit Tastatur- und Screenreader-Nutzer sie sofort erreichen. --}}
            <x-hvm.alert variant="error" label="Fehler" :title="$titel" role="alert" id="fehlerliste" tabindex="-1" x-data x-init="$el.focus()">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($fehlerbeutel->keys() as $schluessel)
                        @foreach ($fehlerbeutel->get($schluessel) as $fehler)
                            <li>
                                <a href="#{{ $feldId($schluessel) }}" class="text-hvm-textschwarz underline decoration-status-error/50 underline-offset-4 hover:decoration-status-error">{{ $fehler }}</a>
                            </li>
                        @endforeach
                    @endforeach
                </ul>
            </x-hvm.alert>
        @endif
    </div>
@endif
