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
        @include('admin.partials.statuszahlen', ['titel' => 'Konten je Status', 'werte' => $statuszahlen, 'enum' => \App\Enums\UserStatus::class])
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

    {{--
        Konten als gestapelte Listenzeilen (Designsystem 4.8): Datenzeile mit
        Etiketten, darunter die Handlungen als Formulare mit sichtbarer
        Begruendung nebeneinander. Eine Tabelle mit Eingabefeldern in einer
        Zelle waere weder auf Desktop noch mobil als Tabelle lesbar.
        Reihenfolge der Handlungen: secondary (Entsperren, Passwort-Reset),
        danger (Sperren, Zweitfaktor zuruecksetzen).
    --}}
    <x-hvm.abschnitt class="mt-16" eyebrow="Bestand" title="Konten" :leer="$nutzer === []" leer-icon="user" :rahmen="false">
        <div class="space-y-4">
            @foreach ($nutzer as $eintrag)
                @php
                    $gesperrt = $eintrag->getAttribute('status') === \App\Enums\UserStatus::GESPERRT;
                    $rollen = $eintrag->adminRoles->whereNull('revoked_at');
                    $zweitfaktorEingerichtet = $eintrag->getAttribute('two_factor_confirmed_at') !== null;
                    $kennung = $eintrag->getKey();
                @endphp
                <x-hvm.card padding="none">
                    <x-hvm.list-row :stacked="true" :title="$eintrag->getAttribute('name')" :subtitle="$eintrag->getAttribute('email')">
                        <x-slot:actions>
                            <x-hvm.badge :variant="$gesperrt ? 'error' : 'success'" :icon="$gesperrt ? 'lock' : 'check-circle'">{{ $eintrag->getAttribute('status')->label() }}</x-hvm.badge>
                            <x-hvm.badge :variant="$zweitfaktorEingerichtet ? 'success' : 'neutral'" :icon="$zweitfaktorEingerichtet ? 'shield' : 'clock'">Zweiter Faktor {{ $zweitfaktorEingerichtet ? 'eingerichtet' : 'nicht eingerichtet' }}</x-hvm.badge>
                        </x-slot:actions>

                        <div class="border-t border-hvm-linie pt-5">
                            <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
                                <div class="min-w-0">
                                    <dt class="text-xs font-semibold tracking-[0.08em] text-hvm-text-sekundaer uppercase">E-Mail</dt>
                                    <dd class="mt-1 text-hvm-textschwarz [overflow-wrap:anywhere]">{{ $eintrag->getAttribute('email') }}</dd>
                                </div>
                                <div class="min-w-0">
                                    <dt class="text-xs font-semibold tracking-[0.08em] text-hvm-text-sekundaer uppercase">Status</dt>
                                    <dd class="mt-1 text-hvm-textschwarz">{{ $eintrag->getAttribute('status')->label() }}</dd>
                                </div>
                                <div class="min-w-0">
                                    <dt class="text-xs font-semibold tracking-[0.08em] text-hvm-text-sekundaer uppercase">Interne Rolle</dt>
                                    <dd class="mt-1 text-hvm-textschwarz">
                                        {{ $rollen->isEmpty() ? 'keine' : $rollen->map(fn ($rolle) => $rolle->getAttribute('role')->label())->implode(', ') }}
                                    </dd>
                                </div>
                            </dl>

                            <div class="mt-5 grid grid-cols-1 gap-4 border-t border-hvm-linie pt-5 lg:grid-cols-3">
                                <form method="POST"
                                      action="{{ $gesperrt ? route('admin.nutzer.entsperren', $eintrag) : route('admin.nutzer.sperren', $eintrag) }}"
                                      class="flex min-w-0 flex-col gap-3 rounded-2xl bg-hvm-canvas p-4">
                                    @csrf
                                    <x-hvm.field name="grund" :id="'grund-'.$kennung" label="Begründung" :required="true" :errors="false"
                                                 placeholder="Begründung" class="min-h-11 py-2 text-sm" />
                                    <div class="mt-auto">
                                        <x-hvm.button type="submit" :variant="$gesperrt ? 'secondary' : 'danger'" size="sm">
                                            @if (! $gesperrt)
                                                <x-hvm.icon name="lock" class="h-4 w-4" />
                                            @endif
                                            {{ $gesperrt ? 'Entsperren' : 'Sperren' }}
                                        </x-hvm.button>
                                    </div>
                                </form>

                                <form method="POST" action="{{ route('admin.nutzer.passwort', $eintrag) }}"
                                      class="flex min-w-0 flex-col gap-3 rounded-2xl bg-hvm-canvas p-4">
                                    @csrf
                                    <p class="text-sm leading-relaxed text-hvm-text-sekundaer">
                                        Der Adminbereich setzt niemals selbst ein Passwort; der Nutzer erhält einen Link.
                                    </p>
                                    <div class="mt-auto">
                                        <x-hvm.button type="submit" variant="secondary" size="sm">
                                            <x-hvm.icon name="mail" class="h-4 w-4" />
                                            Passwort-Reset senden
                                        </x-hvm.button>
                                    </div>
                                </form>

                                @if ($zweitfaktorEingerichtet)
                                    <form method="POST" action="{{ route('admin.nutzer.zweitfaktor', $eintrag) }}"
                                          class="flex min-w-0 flex-col gap-3 rounded-2xl bg-hvm-canvas p-4">
                                        @csrf
                                        <x-hvm.field name="grund" :id="'zweitfaktor-grund-'.$kennung" label="Begründung und Identitätsprüfung" :required="true" :errors="false"
                                                     placeholder="Begründung und Identitätsprüfung" class="min-h-11 py-2 text-sm" />
                                        <p class="text-xs leading-relaxed text-hvm-text-sekundaer">
                                            Nur nach geprüfter Identität, Vier-Augen-Prinzip empfohlen. Beendet alle Sitzungen.
                                        </p>
                                        {{-- Beendet alle Sitzungen: destruktiv, deshalb danger statt Textlink. --}}
                                        <div class="mt-auto">
                                            <x-hvm.button type="submit" variant="danger" size="sm">
                                                <x-hvm.icon name="x-circle" class="h-4 w-4" />
                                                Zweitfaktor zurücksetzen
                                            </x-hvm.button>
                                        </div>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </x-hvm.list-row>
                </x-hvm.card>
            @endforeach
        </div>
    </x-hvm.abschnitt>
@endsection
