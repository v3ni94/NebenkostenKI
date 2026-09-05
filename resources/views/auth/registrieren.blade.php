@extends('layouts.portal')

@section('titel', 'Konto anlegen')
@section('ohne_navigation', 'ja')

@section('content')
    <div class="mx-auto max-w-md py-4 sm:py-10">
        <x-hvm.section-heading
            eyebrow="Schritt 1"
            title="Konto anlegen"
            lead="Ihr Konto und alle Entwürfe sind kostenlos. Bezahlt wird erst, wenn Sie die Vorschau geprüft haben." />

        <x-hvm.card :kennlinie="true" padding="none" class="mt-10 rounded-3xl">
            <form method="POST" action="{{ route('register') }}" class="space-y-6 p-6 sm:p-8">
                @csrf

                <x-hvm.field
                    name="name"
                    label="Name"
                    type="text"
                    autocomplete="name"
                    :required="true" />

                <x-hvm.field
                    name="email"
                    label="E-Mail-Adresse"
                    type="email"
                    autocomplete="email"
                    :required="true" />

                <x-hvm.field
                    name="password"
                    label="Passwort"
                    type="password"
                    autocomplete="new-password"
                    hint="Mindestens 12 Zeichen mit Buchstaben und Ziffern."
                    :required="true" />

                <x-hvm.field
                    name="password_confirmation"
                    label="Passwort wiederholen"
                    type="password"
                    autocomplete="new-password"
                    :required="true" />

                {{--
                    Kontrollkaestchen ohne x-hvm.field, weil die Beschriftung einen
                    Link enthaelt (die Komponente rendert das Label als Text).
                    Aufbau nach Rezept 4.6: .hvm-choice mit .hvm-check.
                --}}
                <div class="min-w-0">
                    <label for="datenschutz" class="hvm-choice items-start">
                        <input id="datenschutz" name="datenschutz" type="checkbox" value="1"
                               class="hvm-check mt-0.5"
                               @checked(old('datenschutz') !== null)
                               @error('datenschutz') aria-invalid="true" aria-describedby="datenschutz-fehler" @enderror>
                        <span class="min-w-0">
                            Ich habe die
                            <a class="font-medium underline decoration-hvm-hellgrau underline-offset-4 hover:decoration-hvm-textschwarz" href="{{ route('legal.datenschutz') }}">Datenschutzerklärung</a>
                            zur Kenntnis genommen.
                        </span>
                    </label>
                    @error('datenschutz')
                        <p id="datenschutz-fehler" class="mt-1 flex items-start gap-1.5 text-sm font-medium text-status-error">
                            <x-hvm.icon name="warning" class="mt-0.5 h-4 w-4" />
                            <span>{{ $message }}</span>
                        </p>
                    @enderror
                </div>

                <x-hvm.button type="submit" variant="primary" size="lg" class="w-full">Konto anlegen</x-hvm.button>
            </form>
        </x-hvm.card>

        <p class="mt-8 text-sm text-hvm-text-sekundaer">
            Sie haben bereits ein Konto?
            <a class="font-medium text-hvm-textschwarz underline decoration-hvm-hellgrau underline-offset-4 hover:decoration-hvm-textschwarz" href="{{ route('login') }}">Hier anmelden</a>
        </p>
    </div>
@endsection
