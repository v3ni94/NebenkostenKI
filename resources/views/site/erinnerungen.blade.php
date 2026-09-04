{{--
    Bestaetigungsseite fuer Abmeldung und erneute Aktivierung der Erinnerungen.

    Die Seite wird ueber den signierten Link aus der Erinnerungsmail geoeffnet
    und aendert selbst nichts. Erst das Absenden des Formulars fuehrt die
    Aenderung aus (Masterprompt 17.2). Es werden keine Kontodaten angezeigt,
    hoechstens die Bezeichnung des Objekts.
--}}
@extends('layouts.site')

@section('meta_title', $abmeldung ? 'Erinnerungen abmelden' : 'Erinnerungen aktivieren')
@section('meta_description', 'Bestätigung der Änderung Ihrer Erinnerungseinstellungen bei Smart Abrechnen.')

@section('content')
    <div class="mx-auto max-w-2xl px-4 py-12 sm:px-6 sm:py-16">
        <x-hvm.card :accent="true" :title="$abmeldung ? 'Erinnerungen abmelden' : 'Erinnerungen wieder aktivieren'">
            @if ($abmeldung)
                <p class="text-hvm-textschwarz">
                    @if ($objekt !== null)
                        Sie möchten die Erinnerungen für das Objekt <strong>{{ $objekt }}</strong> abmelden.
                    @else
                        Sie möchten Ihre Erinnerungen zur Betriebskostenabrechnung abmelden.
                    @endif
                </p>
                <p class="mt-3 text-sm text-hvm-anthrazit">
                    Nachrichten zu Konto, Zahlung und Rechnung erhalten Sie weiterhin. In Ihrem Konto können Sie die
                    Erinnerungen jederzeit wieder aktivieren.
                </p>
                @unless ($aktiv)
                    <p class="mt-3 text-sm text-hvm-anthrazit">
                        Diese Erinnerungen sind bereits abgemeldet. Ein erneutes Bestätigen ändert daran nichts.
                    </p>
                @endunless
            @else
                <p class="text-hvm-textschwarz">
                    @if ($objekt !== null)
                        Sie möchten die Erinnerungen für das Objekt <strong>{{ $objekt }}</strong> wieder aktivieren.
                    @else
                        Sie möchten Ihre Erinnerungen zur Betriebskostenabrechnung wieder aktivieren.
                    @endif
                </p>
                @if ($aktiv)
                    <p class="mt-3 text-sm text-hvm-anthrazit">
                        Diese Erinnerungen sind bereits aktiv. Ein erneutes Bestätigen ändert daran nichts.
                    </p>
                @endif
            @endif

            <form method="POST" action="{{ $formularUrl }}" class="mt-6 flex flex-wrap gap-3">
                @csrf
                <x-hvm.button type="submit" variant="primary">
                    {{ $abmeldung ? 'Abmeldung bestätigen' : 'Aktivierung bestätigen' }}
                </x-hvm.button>
                <x-hvm.button href="{{ route('site.home') }}" variant="secondary">Abbrechen</x-hvm.button>
            </form>
        </x-hvm.card>
    </div>
@endsection
