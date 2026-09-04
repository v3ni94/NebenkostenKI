{{--
    Nutzerverwaltung.

    Jede Aenderung am Konto verlangt eine Begruendung und wird protokolliert.
    Der Adminbereich setzt niemals selbst ein Passwort.
--}}
@extends('layouts.admin')

@section('titel', 'Nutzer')

@section('content')
    <x-hvm.section-heading
        level="h1"
        title="Nutzer"
        lead="Sperren, Entsperren, Passwort-Reset und Zweitfaktor-Reset verlangen eine Begründung. Jede Handlung wird protokolliert. Interne Kennungen ändert nur die Administration." />

    <div class="mt-6">
        @include('admin.partials.statuszahlen', ['titel' => 'Konten je Status', 'werte' => $statuszahlen])
    </div>

    <div class="mt-6">
        <x-hvm.card title="Suche">
            <form method="GET" action="{{ route('admin.nutzer') }}" class="flex flex-wrap items-end gap-3">
                <div>
                    <label for="suche" class="block text-sm font-semibold">Name oder E-Mail-Adresse</label>
                    <input type="search" id="suche" name="suche" value="{{ $suche }}"
                           class="mt-2 rounded border border-hvm-mittelgrau px-3 py-2">
                </div>
                <x-hvm.button type="submit" variant="secondary" size="sm">Suchen</x-hvm.button>
            </form>
        </x-hvm.card>
    </div>

    <div class="mt-6">
        <x-hvm.card title="Konten">
            @if ($nutzer === [])
                <p>Kein Eintrag.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-hvm-orange-soft">
                            <tr>
                                <th class="px-3 py-2">Name</th>
                                <th class="px-3 py-2">E-Mail</th>
                                <th class="px-3 py-2">Status</th>
                                <th class="px-3 py-2">Interne Rolle</th>
                                <th class="px-3 py-2">Zweiter Faktor</th>
                                <th class="px-3 py-2">Handlungen</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($nutzer as $eintrag)
                                @php
                                    $gesperrt = $eintrag->getAttribute('status') === \App\Enums\UserStatus::GESPERRT;
                                    $rollen = $eintrag->adminRoles->whereNull('revoked_at');
                                @endphp
                                <tr class="border-t border-hvm-hellgrau align-top">
                                    <td class="px-3 py-2">{{ $eintrag->getAttribute('name') }}</td>
                                    <td class="px-3 py-2">{{ $eintrag->getAttribute('email') }}</td>
                                    <td class="px-3 py-2">{{ $eintrag->getAttribute('status')->label() }}</td>
                                    <td class="px-3 py-2">
                                        {{ $rollen->isEmpty() ? 'keine' : $rollen->map(fn ($rolle) => $rolle->getAttribute('role')->label())->implode(', ') }}
                                    </td>
                                    <td class="px-3 py-2">{{ $eintrag->getAttribute('two_factor_confirmed_at') === null ? 'nicht eingerichtet' : 'eingerichtet' }}</td>
                                    <td class="px-3 py-2">
                                        <form method="POST"
                                              action="{{ $gesperrt ? route('admin.nutzer.entsperren', $eintrag) : route('admin.nutzer.sperren', $eintrag) }}"
                                              class="space-y-2">
                                            @csrf
                                            <label class="sr-only" for="grund-{{ $eintrag->getKey() }}">Begründung</label>
                                            <input type="text" id="grund-{{ $eintrag->getKey() }}" name="grund" required
                                                   placeholder="Begründung"
                                                   class="w-56 rounded border border-hvm-mittelgrau px-2 py-1">
                                            <x-hvm.button type="submit" variant="secondary" size="sm">
                                                {{ $gesperrt ? 'Entsperren' : 'Sperren' }}
                                            </x-hvm.button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.nutzer.passwort', $eintrag) }}" class="mt-2">
                                            @csrf
                                            <x-hvm.button type="submit" variant="ghost" size="sm">
                                                Passwort-Reset senden
                                            </x-hvm.button>
                                        </form>
                                        @if ($eintrag->getAttribute('two_factor_confirmed_at') !== null)
                                            <form method="POST" action="{{ route('admin.nutzer.zweitfaktor', $eintrag) }}"
                                                  class="mt-2 space-y-2">
                                                @csrf
                                                <label class="sr-only" for="zweitfaktor-grund-{{ $eintrag->getKey() }}">
                                                    Begründung und Identitätsprüfung
                                                </label>
                                                <input type="text" id="zweitfaktor-grund-{{ $eintrag->getKey() }}" name="grund" required
                                                       placeholder="Begründung und Identitätsprüfung"
                                                       class="w-56 rounded border border-hvm-mittelgrau px-2 py-1">
                                                <x-hvm.button type="submit" variant="ghost" size="sm">
                                                    Zweitfaktor zurücksetzen
                                                </x-hvm.button>
                                                <p class="text-xs text-hvm-dunkelgrau">
                                                    Nur nach geprüfter Identität, Vier-Augen-Prinzip empfohlen. Beendet alle Sitzungen.
                                                </p>
                                            </form>
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
@endsection
