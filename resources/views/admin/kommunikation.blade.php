{{--
    E-Mail-Status, Sperrliste, Erinnerungstermine und Vorlagen.

    Es wird kein Nachrichteninhalt, kein Downloadlink und kein Token angezeigt.
--}}
@extends('layouts.admin')

@section('titel', 'Kommunikation')

@section('content')
    <x-hvm.page-header
        eyebrow="Kommunikation"
        title="E-Mail und Erinnerungen"
        lead="Sichtbar sind Vorlage, Empfänger, Status und Fehlercode. Nachrichteninhalte und Downloadlinks werden nicht angezeigt." />

    <div class="mt-10 grid grid-cols-1 gap-6 lg:grid-cols-2 lg:items-start">
        @include('admin.partials.statuszahlen', ['titel' => 'E-Mails je Status', 'werte' => $mailstatus, 'enum' => \App\Enums\EmailStatus::class])
        @include('admin.partials.statuszahlen', ['titel' => 'Erinnerungen je Status', 'werte' => $erinnerungsstatus])
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2 lg:items-start">
        <x-hvm.card title="Erinnerungsplan des laufenden Jahres" eyebrow="Termine" class="min-w-0">
            <p class="flex flex-wrap items-center gap-2 text-sm">
                <x-hvm.badge :variant="$erinnerungen_aktiv ? 'success' : 'neutral'" :icon="$erinnerungen_aktiv ? 'check-circle' : 'clock'">{{ $erinnerungen_aktiv ? 'aktiv' : 'abgeschaltet' }}</x-hvm.badge>
                <span class="text-hvm-text-sekundaer">Erinnerungen sind {{ $erinnerungen_aktiv ? 'aktiv' : 'abgeschaltet' }}.</span>
            </p>
            <dl class="mt-4 divide-y divide-hvm-linie">
                @foreach ($erinnerungsplan as $fenster => $termin)
                    <x-hvm.kv :label="$fenster">{{ $termin }}</x-hvm.kv>
                @endforeach
            </dl>
        </x-hvm.card>

        @include('admin.partials.statuszahlen', ['titel' => 'Erinnerungen je Fenster', 'werte' => $erinnerungsfenster])
    </div>

    <div class="mt-6">
        <x-hvm.card title="Verwendete Vorlagen" eyebrow="Vorlagen">
            @if ($vorlagen === [])
                <p class="text-sm text-hvm-text-sekundaer">Kein Eintrag.</p>
            @else
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($vorlagen as $vorlage => $anzahl)
                        <x-hvm.stat size="sm" tone="canvas" :icon="false" :label="$vorlage" :value="$anzahl" />
                    @endforeach
                </div>
            @endif
        </x-hvm.card>
    </div>

    <x-hvm.abschnitt class="mt-16" eyebrow="Zustellung" title="Fehlgeschlagene E-Mails" :leer="$fehlgeschlagen === []" leer-icon="mail">
        <table class="hvm-table hvm-table-zebra hvm-table-stack text-sm">
            <caption class="sr-only">Fehlgeschlagene E-Mails</caption>
            <thead>
                <tr>
                    <th scope="col">Vorlage</th>
                    <th scope="col">Empfänger</th>
                    <th scope="col">Status</th>
                    <th scope="col">Fehlercode</th>
                    <th scope="col">Versuche</th>
                    <th scope="col">Handlung</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($fehlgeschlagen as $nachricht)
                    <tr>
                        <th scope="row" class="font-mono text-xs font-medium">{{ $nachricht->getAttribute('template') }}</th>
                        <td data-label="Empfänger">{{ $nachricht->getAttribute('recipient_email') }}</td>
                        <td data-label="Status">
                            <x-hvm.badge variant="error" icon="x-circle">{{ $nachricht->getAttribute('status')->label() }}</x-hvm.badge>
                        </td>
                        <td data-label="Fehlercode" class="font-mono text-xs">{{ $nachricht->getAttribute('error_code') ?? 'ohne Angabe' }}</td>
                        <td data-label="Versuche" class="tabular">{{ $nachricht->getAttribute('attempts') }}</td>
                        <td data-label="Handlung">
                            @if ($wiederholbar($nachricht))
                                {{-- Zeitweiliger Fehler: erneuter Versand aus dem verschluesselten Wiederholungspuffer. --}}
                                <form method="POST" action="{{ route('admin.kommunikation.nachricht.erneut', $nachricht) }}">
                                    @csrf
                                    <x-hvm.button type="submit" variant="secondary" size="sm">Erneut senden</x-hvm.button>
                                </form>
                            @else
                                <span class="text-sm text-hvm-text-sekundaer">keine Wiederholung</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-hvm.abschnitt>

    <x-hvm.abschnitt class="mt-16" eyebrow="Zustellung" title="Sperrliste" :leer="$sperrliste === []" leer-icon="mail">
        <table class="hvm-table hvm-table-zebra hvm-table-stack text-sm">
            <caption class="sr-only">Sperrliste</caption>
            <thead>
                <tr>
                    <th scope="col">Adresse</th>
                    <th scope="col">Grund</th>
                    <th scope="col">Gesperrt am</th>
                    <th scope="col">Sperre aufheben</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sperrliste as $eintrag)
                    <tr>
                        <th scope="row" class="font-medium">{{ $eintrag->getAttribute('email') }}</th>
                        <td data-label="Grund">{{ $eintrag->getAttribute('reason')->label() }}</td>
                        <td data-label="Gesperrt am" class="text-hvm-text-sekundaer">{{ \Illuminate\Support\Carbon::parse((string) $eintrag->getAttribute('suppressed_at'))->format('d.m.Y') }}</td>
                        <td data-label="Sperre aufheben">
                            {{-- Zum Beispiel nach einem SMTP-Ausfall, der faelschlich als Unzustellbarkeit gewertet wurde. --}}
                            <form method="POST" action="{{ route('admin.kommunikation.sperre.aufheben') }}" class="flex max-w-sm flex-col gap-2 sm:flex-row sm:items-end">
                                @csrf
                                <input type="hidden" name="email" value="{{ $eintrag->getAttribute('email') }}">
                                {{-- Sichtbare Beschriftung statt Platzhalter als einziger Bezeichnung. --}}
                                <x-hvm.field name="grund" :id="'grund-'.$eintrag->getKey()" label="Begründung" :required="true"
                                             :errors="false" placeholder="Begründung" wrapperClass="min-w-0 flex-1" class="min-h-11 py-2 text-sm" />
                                <x-hvm.button type="submit" variant="secondary" size="sm" class="shrink-0">Aufheben</x-hvm.button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-hvm.abschnitt>

    <x-hvm.abschnitt class="mt-16" eyebrow="Erinnerungen" title="Anstehende Erinnerungen" :leer="$anstehend === []" leer-icon="calendar">
        <table class="hvm-table hvm-table-zebra hvm-table-stack text-sm">
            <caption class="sr-only">Anstehende Erinnerungen</caption>
            <thead>
                <tr>
                    <th scope="col">Fällig am</th>
                    <th scope="col">Fenster</th>
                    <th scope="col">Abrechnungsjahr</th>
                    <th scope="col">Empfänger</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($anstehend as $termin)
                    <tr>
                        <th scope="row" class="font-medium tabular">{{ \Illuminate\Support\Carbon::parse((string) $termin->getAttribute('scheduled_for'))->format('d.m.Y') }}</th>
                        <td data-label="Fenster">{{ $termin->getAttribute('reminder_window')->label() }}</td>
                        <td data-label="Abrechnungsjahr" class="tabular">{{ $termin->getAttribute('billing_year') }}</td>
                        <td data-label="Empfänger">{{ $termin->getAttribute('recipient_email') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-hvm.abschnitt>
@endsection
