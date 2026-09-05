{{--
    Nutzerverwaltung.

    Jede Aenderung am Konto verlangt eine Begruendung und wird protokolliert.
    Der Adminbereich setzt niemals selbst ein Passwort.
--}}
@extends('layouts.admin')

@section('titel', 'Nutzer')

@section('content')
    <x-hvm.page-header
        eyebrow="Nutzer"
        title="Nutzer"
        lead="Sperren, Entsperren, Passwort-Reset und Zweitfaktor-Reset verlangen eine Begründung. Jede Handlung wird protokolliert. Interne Kennungen ändert nur die Administration." />

    <div class="mt-10">
        @include('admin.partials.statuszahlen', ['titel' => 'Konten je Status', 'werte' => $statuszahlen])
    </div>

    <div class="mt-6">
        <x-hvm.card title="Suche" eyebrow="Filter">
            <form method="GET" action="{{ route('admin.nutzer') }}" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                <div class="min-w-0 flex-1 sm:max-w-md">
                    <x-hvm.field name="suche" label="Name oder E-Mail-Adresse" type="search" :value="$suche" />
                </div>
                <x-hvm.button type="submit" variant="secondary" class="shrink-0">
                    <x-hvm.icon name="search" class="h-4 w-4" />
                    Suchen
                </x-hvm.button>
            </form>
        </x-hvm.card>
    </div>

    <x-hvm.abschnitt class="mt-16" eyebrow="Bestand" title="Konten" :leer="$nutzer === []" leer-icon="user">
        <table class="hvm-table hvm-table-zebra hvm-table-stack text-sm">
            <caption class="sr-only">Konten</caption>
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">E-Mail</th>
                    <th scope="col">Status</th>
                    <th scope="col">Interne Rolle</th>
                    <th scope="col">Zweiter Faktor</th>
                    <th scope="col">Handlungen</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($nutzer as $eintrag)
                    @php
                        $gesperrt = $eintrag->getAttribute('status') === \App\Enums\UserStatus::GESPERRT;
                        $rollen = $eintrag->adminRoles->whereNull('revoked_at');
                        $zweitfaktorEingerichtet = $eintrag->getAttribute('two_factor_confirmed_at') !== null;
                    @endphp
                    <tr>
                        <th scope="row" class="font-medium">{{ $eintrag->getAttribute('name') }}</th>
                        <td data-label="E-Mail">{{ $eintrag->getAttribute('email') }}</td>
                        <td data-label="Status">
                            <x-hvm.badge :variant="$gesperrt ? 'error' : 'success'" :icon="$gesperrt ? 'lock' : 'check-circle'">{{ $eintrag->getAttribute('status')->label() }}</x-hvm.badge>
                        </td>
                        <td data-label="Interne Rolle">
                            {{ $rollen->isEmpty() ? 'keine' : $rollen->map(fn ($rolle) => $rolle->getAttribute('role')->label())->implode(', ') }}
                        </td>
                        <td data-label="Zweiter Faktor">
                            <x-hvm.badge :variant="$zweitfaktorEingerichtet ? 'success' : 'neutral'" :icon="$zweitfaktorEingerichtet ? 'shield' : 'clock'">{{ $zweitfaktorEingerichtet ? 'eingerichtet' : 'nicht eingerichtet' }}</x-hvm.badge>
                        </td>
                        <td data-label="Handlungen">
                            <div class="max-w-xs space-y-4">
                                <form method="POST"
                                      action="{{ $gesperrt ? route('admin.nutzer.entsperren', $eintrag) : route('admin.nutzer.sperren', $eintrag) }}"
                                      class="space-y-2">
                                    @csrf
                                    <label class="sr-only" for="grund-{{ $eintrag->getKey() }}">Begründung</label>
                                    <input type="text" id="grund-{{ $eintrag->getKey() }}" name="grund" required
                                           placeholder="Begründung"
                                           class="hvm-input min-h-11 py-2 text-sm">
                                    <x-hvm.button type="submit" :variant="$gesperrt ? 'secondary' : 'danger'" size="sm">
                                        @if (! $gesperrt)
                                            <x-hvm.icon name="lock" class="h-4 w-4" />
                                        @endif
                                        {{ $gesperrt ? 'Entsperren' : 'Sperren' }}
                                    </x-hvm.button>
                                </form>
                                <form method="POST" action="{{ route('admin.nutzer.passwort', $eintrag) }}">
                                    @csrf
                                    <x-hvm.button type="submit" variant="ghost" size="sm">
                                        Passwort-Reset senden
                                    </x-hvm.button>
                                </form>
                                @if ($zweitfaktorEingerichtet)
                                    <form method="POST" action="{{ route('admin.nutzer.zweitfaktor', $eintrag) }}"
                                          class="space-y-2 border-t border-hvm-linie pt-4">
                                        @csrf
                                        <label class="sr-only" for="zweitfaktor-grund-{{ $eintrag->getKey() }}">
                                            Begründung und Identitätsprüfung
                                        </label>
                                        <input type="text" id="zweitfaktor-grund-{{ $eintrag->getKey() }}" name="grund" required
                                               placeholder="Begründung und Identitätsprüfung"
                                               class="hvm-input min-h-11 py-2 text-sm">
                                        <x-hvm.button type="submit" variant="ghost" size="sm">
                                            Zweitfaktor zurücksetzen
                                        </x-hvm.button>
                                        <p class="text-xs leading-relaxed text-hvm-text-sekundaer">
                                            Nur nach geprüfter Identität, Vier-Augen-Prinzip empfohlen. Beendet alle Sitzungen.
                                        </p>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-hvm.abschnitt>
@endsection
