@extends('layouts.portal')

@section('titel', 'Passwort vergessen')
@section('ohne_navigation', 'ja')

@section('content')
    <div class="mx-auto max-w-xl">
        <x-hvm.section-heading
            title="Passwort zurücksetzen"
            lead="Geben Sie Ihre E-Mail-Adresse an. Wir senden Ihnen einen Link, mit dem Sie ein neues Passwort vergeben." />

        <x-hvm.card class="mt-8">
            <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
                @csrf

                <div>
                    <label for="email" class="block text-sm font-semibold text-hvm-textschwarz">E-Mail-Adresse</label>
                    <input id="email" name="email" type="email" required autocomplete="email" autofocus
                           value="{{ old('email') }}"
                           class="mt-1 block w-full min-h-11 rounded-md border border-hvm-mittelgrau px-3 py-2">
                </div>

                <x-hvm.button type="submit" variant="primary">Link anfordern</x-hvm.button>
            </form>
        </x-hvm.card>

        <p class="mt-6 text-sm text-hvm-textschwarz">
            <a class="font-medium underline underline-offset-2" href="{{ route('login') }}">Zurück zur Anmeldung</a>
        </p>
    </div>
@endsection
