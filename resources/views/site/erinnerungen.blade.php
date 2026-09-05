{{--
    Bestaetigungsseite fuer Abmeldung und erneute Aktivierung der Erinnerungen.

    Die Seite wird ueber den signierten Link aus der Erinnerungsmail geoeffnet
    und aendert selbst nichts. Erst das Absenden des Formulars fuehrt die
    Aenderung aus (Masterprompt 17.2). Es werden keine Kontodaten angezeigt,
    hoechstens die Bezeichnung des Objekts.

    Aufbau nach dem Formularmuster des Designsystems (4.6): schmale Spalte,
    Seitenkopf, Karte mit Kennlinie, Buttonreihe mit genau einem Primaerbutton.
--}}
@extends('layouts.site')

@section('meta_title', $abmeldung ? 'Erinnerungen abmelden' : 'Erinnerungen aktivieren')
@section('meta_description', 'Bestätigung der Änderung Ihrer Erinnerungseinstellungen bei Smart Abrechnen.')

@section('content')
    <section class="bg-hvm-canvas">
        <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8 lg:py-24">
            <div class="mx-auto max-w-md">
                <x-hvm.section-heading
                    level="h1"
                    eyebrow="Erinnerungen"
                    :title="$abmeldung ? 'Erinnerungen abmelden' : 'Erinnerungen wieder aktivieren'" />

                <x-hvm.card :kennlinie="true" padding="none" class="mt-10 rounded-3xl">
                    <div class="p-6 sm:p-8">
                        <div class="flex gap-4">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark" aria-hidden="true">
                                <x-hvm.icon name="calendar" />
                            </span>
                            <div class="min-w-0 text-base leading-relaxed text-hvm-textschwarz">
                                @if ($abmeldung)
                                    <p>
                                        @if ($objekt !== null)
                                            Sie möchten die Erinnerungen für das Objekt <strong>{{ $objekt }}</strong> abmelden.
                                        @else
                                            Sie möchten Ihre Erinnerungen zur Betriebskostenabrechnung abmelden.
                                        @endif
                                    </p>
                                    <p class="mt-3 text-sm text-hvm-text-sekundaer">
                                        Nachrichten zu Konto, Zahlung und Rechnung erhalten Sie weiterhin. In Ihrem Konto können Sie die
                                        Erinnerungen jederzeit wieder aktivieren.
                                    </p>
                                @else
                                    <p>
                                        @if ($objekt !== null)
                                            Sie möchten die Erinnerungen für das Objekt <strong>{{ $objekt }}</strong> wieder aktivieren.
                                        @else
                                            Sie möchten Ihre Erinnerungen zur Betriebskostenabrechnung wieder aktivieren.
                                        @endif
                                    </p>
                                @endif
                            </div>
                        </div>

                        @if ($abmeldung && ! $aktiv)
                            <x-hvm.alert class="mt-6" variant="info">
                                Diese Erinnerungen sind bereits abgemeldet. Ein erneutes Bestätigen ändert daran nichts.
                            </x-hvm.alert>
                        @elseif (! $abmeldung && $aktiv)
                            <x-hvm.alert class="mt-6" variant="info">
                                Diese Erinnerungen sind bereits aktiv. Ein erneutes Bestätigen ändert daran nichts.
                            </x-hvm.alert>
                        @endif

                        <form method="POST" action="{{ $formularUrl }}" class="mt-8 flex flex-wrap gap-3 border-t border-hvm-linie pt-6">
                            @csrf
                            <x-hvm.button type="submit" variant="primary" class="w-full sm:w-auto">
                                {{ $abmeldung ? 'Abmeldung bestätigen' : 'Aktivierung bestätigen' }}
                            </x-hvm.button>
                            <x-hvm.button href="{{ route('site.home') }}" variant="ghost" class="w-full sm:w-auto">Abbrechen</x-hvm.button>
                        </form>
                    </div>
                </x-hvm.card>
            </div>
        </div>
    </section>
@endsection
