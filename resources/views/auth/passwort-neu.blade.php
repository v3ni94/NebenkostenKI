@extends('layouts.portal')

@section('titel', 'Neues Passwort')
@section('ohne_navigation', 'ja')

@section('content')
    <div class="mx-auto max-w-md py-4 sm:py-10">
        <x-hvm.section-heading
            eyebrow="Portal"
            title="Neues Passwort vergeben"
            lead="Bitte vergeben Sie ein neues Passwort. Der Link lässt sich nur einmal verwenden." />

        <x-hvm.card :kennlinie="true" padding="none" class="mt-10 rounded-3xl">
            <form method="POST" action="{{ route('password.update') }}" class="space-y-6 p-6 sm:p-8">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">

                <x-hvm.field
                    name="email"
                    label="E-Mail-Adresse"
                    type="email"
                    autocomplete="email"
                    :value="old('email', $email)"
                    :required="true" />

                <x-hvm.field
                    name="password"
                    label="Neues Passwort"
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

                <x-hvm.button type="submit" variant="primary" size="lg" class="w-full">Passwort speichern</x-hvm.button>
            </form>
        </x-hvm.card>
    </div>
@endsection
