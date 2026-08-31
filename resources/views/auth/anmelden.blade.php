@extends('layouts.portal')

@section('titel', 'Anmelden')
@section('ohne_navigation', 'ja')

@section('content')
    <div class="mx-auto max-w-xl">
        <x-hvm.section-heading
            title="Anmelden"
            lead="Melden Sie sich mit Ihrer E-Mail-Adresse und Ihrem Passwort an." />

        <x-hvm.card class="mt-8">
            <form method="POST" action="{{ route('login') }}" class="space-y-5">
                @csrf

                <div>
                    <label for="email" class="block text-sm font-semibold text-hvm-textschwarz">E-Mail-Adresse</label>
                    <input id="email" name="email" type="email" required autocomplete="email" autofocus
                           value="{{ old('email') }}"
                           @error('email') aria-invalid="true" aria-describedby="email-fehler" @enderror
                           class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                    @error('email')
                        <p id="email-fehler" class="mt-1 text-sm text-status-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="block text-sm font-semibold text-hvm-textschwarz">Passwort</label>
                    <input id="password" name="password" type="password" required autocomplete="current-password"
                           class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                </div>

                <div class="flex items-center gap-3">
                    <input id="remember" name="remember" type="checkbox" value="1"
                           class="h-5 w-5 rounded border-hvm-mittelgrau">
                    <label for="remember" class="text-sm text-hvm-textschwarz">Angemeldet bleiben</label>
                </div>

                <x-hvm.button type="submit" variant="primary">Anmelden</x-hvm.button>
            </form>
        </x-hvm.card>

        <div class="mt-6 space-y-2 text-sm text-hvm-textschwarz">
            <p>
                <a class="font-medium underline underline-offset-2" href="{{ route('password.request') }}">
                    Passwort vergessen
                </a>
            </p>
            <p>
                Noch kein Konto?
                <a class="font-medium underline underline-offset-2" href="{{ route('register') }}">Jetzt anlegen</a>
            </p>
        </div>
    </div>
@endsection
