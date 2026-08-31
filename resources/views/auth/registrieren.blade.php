@extends('layouts.portal')

@section('titel', 'Konto anlegen')
@section('ohne_navigation', 'ja')

@section('content')
    <div class="mx-auto max-w-xl">
        <x-hvm.section-heading
            eyebrow="Schritt 1"
            title="Konto anlegen"
            lead="Ihr Konto und alle Entwürfe sind kostenlos. Bezahlt wird erst, wenn Sie die Vorschau geprüft haben." />

        <x-hvm.card class="mt-8">
            <form method="POST" action="{{ route('register') }}" class="space-y-5">
                @csrf

                <div>
                    <label for="name" class="block text-sm font-semibold text-hvm-textschwarz">Name</label>
                    <input id="name" name="name" type="text" required autocomplete="name"
                           value="{{ old('name') }}"
                           @error('name') aria-invalid="true" aria-describedby="name-fehler" @enderror
                           class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                    @error('name')
                        <p id="name-fehler" class="mt-1 text-sm text-status-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="email" class="block text-sm font-semibold text-hvm-textschwarz">E-Mail-Adresse</label>
                    <input id="email" name="email" type="email" required autocomplete="email"
                           value="{{ old('email') }}"
                           @error('email') aria-invalid="true" aria-describedby="email-fehler" @enderror
                           class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                    @error('email')
                        <p id="email-fehler" class="mt-1 text-sm text-status-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="block text-sm font-semibold text-hvm-textschwarz">Passwort</label>
                    <input id="password" name="password" type="password" required autocomplete="new-password"
                           @error('password') aria-invalid="true" aria-describedby="password-hinweis password-fehler" @else aria-describedby="password-hinweis" @enderror
                           class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                    <p id="password-hinweis" class="mt-1 text-sm text-hvm-anthrazit">
                        Mindestens 12 Zeichen mit Buchstaben und Ziffern.
                    </p>
                    @error('password')
                        <p id="password-fehler" class="mt-1 text-sm text-status-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm font-semibold text-hvm-textschwarz">
                        Passwort wiederholen
                    </label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required
                           autocomplete="new-password"
                           class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                </div>

                <div class="flex items-start gap-3">
                    <input id="datenschutz" name="datenschutz" type="checkbox" value="1"
                           class="mt-1 h-5 w-5 rounded border-hvm-mittelgrau">
                    <label for="datenschutz" class="text-sm text-hvm-textschwarz">
                        Ich habe die
                        <a class="underline underline-offset-2" href="{{ route('legal.datenschutz') }}">Datenschutzerklärung</a>
                        zur Kenntnis genommen.
                    </label>
                </div>
                @error('datenschutz')
                    <p class="text-sm text-status-error">{{ $message }}</p>
                @enderror

                <x-hvm.button type="submit" variant="primary">Konto anlegen</x-hvm.button>
            </form>
        </x-hvm.card>

        <p class="mt-6 text-sm text-hvm-textschwarz">
            Sie haben bereits ein Konto?
            <a class="font-medium underline underline-offset-2" href="{{ route('login') }}">Hier anmelden</a>
        </p>
    </div>
@endsection
