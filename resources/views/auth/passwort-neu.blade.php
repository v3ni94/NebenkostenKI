@extends('layouts.portal')

@section('titel', 'Neues Passwort')
@section('ohne_navigation', 'ja')

@section('content')
    <div class="mx-auto max-w-xl">
        <x-hvm.section-heading
            title="Neues Passwort vergeben"
            lead="Bitte vergeben Sie ein neues Passwort. Der Link lässt sich nur einmal verwenden." />

        <x-hvm.card class="mt-8">
            <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">

                <div>
                    <label for="email" class="block text-sm font-semibold text-hvm-textschwarz">E-Mail-Adresse</label>
                    <input id="email" name="email" type="email" required autocomplete="email"
                           value="{{ old('email', $email) }}"
                           class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                </div>

                <div>
                    <label for="password" class="block text-sm font-semibold text-hvm-textschwarz">Neues Passwort</label>
                    <input id="password" name="password" type="password" required autocomplete="new-password"
                           aria-describedby="password-hinweis"
                           class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                    <p id="password-hinweis" class="mt-1 text-sm text-hvm-anthrazit">
                        Mindestens 12 Zeichen mit Buchstaben und Ziffern.
                    </p>
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm font-semibold text-hvm-textschwarz">
                        Passwort wiederholen
                    </label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required
                           autocomplete="new-password"
                           class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                </div>

                <x-hvm.button type="submit" variant="primary">Passwort speichern</x-hvm.button>
            </form>
        </x-hvm.card>
    </div>
@endsection
