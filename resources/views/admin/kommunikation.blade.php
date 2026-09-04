{{--
    E-Mail-Status, Sperrliste, Erinnerungstermine und Vorlagen.

    Es wird kein Nachrichteninhalt, kein Downloadlink und kein Token angezeigt.
--}}
@extends('layouts.admin')

@section('titel', 'Kommunikation')

@section('content')
    <x-hvm.section-heading
        level="h1"
        title="E-Mail und Erinnerungen"
        lead="Sichtbar sind Vorlage, Empfänger, Status und Fehlercode. Nachrichteninhalte und Downloadlinks werden nicht angezeigt." />

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        @include('admin.partials.statuszahlen', ['titel' => 'E-Mails je Status', 'werte' => $mailstatus])
        @include('admin.partials.statuszahlen', ['titel' => 'Erinnerungen je Status', 'werte' => $erinnerungsstatus])
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <x-hvm.card title="Erinnerungsplan des laufenden Jahres">
            <p class="text-sm text-hvm-anthrazit">
                Erinnerungen sind {{ $erinnerungen_aktiv ? 'aktiv' : 'abgeschaltet' }}.
            </p>
            <dl class="mt-3 space-y-1 text-sm">
                @foreach ($erinnerungsplan as $fenster => $termin)
                    <div class="flex justify-between"><dt>{{ $fenster }}</dt><dd>{{ $termin }}</dd></div>
                @endforeach
            </dl>
        </x-hvm.card>

        @include('admin.partials.statuszahlen', ['titel' => 'Erinnerungen je Fenster', 'werte' => $erinnerungsfenster])
    </div>

    <div class="mt-6">
        <x-hvm.card title="Verwendete Vorlagen">
            @if ($vorlagen === [])
                <p>Kein Eintrag.</p>
            @else
                <dl class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                    @foreach ($vorlagen as $vorlage => $anzahl)
                        <div class="flex justify-between rounded border border-hvm-hellgrau px-3 py-2">
                            <dt>{{ $vorlage }}</dt>
                            <dd>{{ $anzahl }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </x-hvm.card>
    </div>

    <div class="mt-6">
        <x-hvm.card title="Fehlgeschlagene E-Mails">
            @if ($fehlgeschlagen === [])
                <p>Kein Eintrag.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-hvm-orange-soft">
                            <tr>
                                <th class="px-3 py-2">Vorlage</th>
                                <th class="px-3 py-2">Empfänger</th>
                                <th class="px-3 py-2">Status</th>
                                <th class="px-3 py-2">Fehlercode</th>
                                <th class="px-3 py-2">Versuche</th>
                                <th class="px-3 py-2">Handlung</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($fehlgeschlagen as $nachricht)
                                <tr class="border-t border-hvm-hellgrau">
                                    <td class="px-3 py-2">{{ $nachricht->getAttribute('template') }}</td>
                                    <td class="px-3 py-2">{{ $nachricht->getAttribute('recipient_email') }}</td>
                                    <td class="px-3 py-2">{{ $nachricht->getAttribute('status')->label() }}</td>
                                    <td class="px-3 py-2">{{ $nachricht->getAttribute('error_code') ?? 'ohne Angabe' }}</td>
                                    <td class="px-3 py-2">{{ $nachricht->getAttribute('attempts') }}</td>
                                    <td class="px-3 py-2">
                                        @if ($wiederholbar($nachricht))
                                            {{-- Zeitweiliger Fehler: erneuter Versand aus dem verschluesselten Wiederholungspuffer. --}}
                                            <form method="POST" action="{{ route('admin.kommunikation.nachricht.erneut', $nachricht) }}">
                                                @csrf
                                                <x-hvm.button type="submit" variant="secondary" size="sm">Erneut senden</x-hvm.button>
                                            </form>
                                        @else
                                            <span class="text-xs text-hvm-anthrazit">keine Wiederholung</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-hvm.card>
    </div>

    <div class="mt-6">
        <x-hvm.card title="Sperrliste">
            @if ($sperrliste === [])
                <p>Kein Eintrag.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-hvm-orange-soft">
                            <tr>
                                <th class="px-3 py-2">Adresse</th>
                                <th class="px-3 py-2">Grund</th>
                                <th class="px-3 py-2">Gesperrt am</th>
                                <th class="px-3 py-2">Sperre aufheben</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sperrliste as $eintrag)
                                <tr class="border-t border-hvm-hellgrau">
                                    <td class="px-3 py-2">{{ $eintrag->getAttribute('email') }}</td>
                                    <td class="px-3 py-2">{{ $eintrag->getAttribute('reason')->label() }}</td>
                                    <td class="px-3 py-2">{{ \Illuminate\Support\Carbon::parse((string) $eintrag->getAttribute('suppressed_at'))->format('d.m.Y') }}</td>
                                    <td class="px-3 py-2">
                                        {{-- Zum Beispiel nach einem SMTP-Ausfall, der faelschlich als Unzustellbarkeit gewertet wurde. --}}
                                        <form method="POST" action="{{ route('admin.kommunikation.sperre.aufheben') }}" class="space-y-2">
                                            @csrf
                                            <input type="hidden" name="email" value="{{ $eintrag->getAttribute('email') }}">
                                            <label class="sr-only" for="grund-{{ $eintrag->getKey() }}">Begründung</label>
                                            <input type="text" id="grund-{{ $eintrag->getKey() }}" name="grund" required
                                                   placeholder="Begründung"
                                                   class="w-56 rounded border border-hvm-mittelgrau px-2 py-1">
                                            <x-hvm.button type="submit" variant="secondary" size="sm">Aufheben</x-hvm.button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-hvm.card>
    </div>

    <div class="mt-6">
        <x-hvm.card title="Anstehende Erinnerungen">
            @if ($anstehend === [])
                <p>Kein Eintrag.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-hvm-orange-soft">
                            <tr>
                                <th class="px-3 py-2">Fällig am</th>
                                <th class="px-3 py-2">Fenster</th>
                                <th class="px-3 py-2">Abrechnungsjahr</th>
                                <th class="px-3 py-2">Empfänger</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($anstehend as $termin)
                                <tr class="border-t border-hvm-hellgrau">
                                    <td class="px-3 py-2">{{ \Illuminate\Support\Carbon::parse((string) $termin->getAttribute('scheduled_for'))->format('d.m.Y') }}</td>
                                    <td class="px-3 py-2">{{ $termin->getAttribute('reminder_window')->label() }}</td>
                                    <td class="px-3 py-2">{{ $termin->getAttribute('billing_year') }}</td>
                                    <td class="px-3 py-2">{{ $termin->getAttribute('recipient_email') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-hvm.card>
    </div>
@endsection
