@extends('layouts.portal')

@section('titel', 'E-Mail-Adresse bestätigen')

@section('content')
    <x-hvm.page-header
        eyebrow="Konto"
        title="Bitte bestätigen Sie Ihre E-Mail-Adresse"
        lead="Wir haben Ihnen eine E-Mail gesendet. Öffnen Sie den darin enthaltenen Link, um Ihre Adresse zu bestätigen." />

    <div class="mt-10 max-w-2xl">
        <x-hvm.card :kennlinie="true" padding="none" class="rounded-3xl">
            <div class="p-6 sm:p-8">
                <div class="flex gap-4">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark" aria-hidden="true">
                        <x-hvm.icon name="mail" class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-base leading-relaxed text-hvm-textschwarz">
                            Sie können Ihre Objekte, Einheiten und Abrechnungsentwürfe bereits jetzt anlegen. Für die Zahlung
                            und den Download der fertigen Abrechnungen ist die Bestätigung erforderlich.
                        </p>

                        <div class="mt-5 rounded-2xl bg-hvm-canvas p-4">
                            <p class="text-sm text-hvm-text-sekundaer">Ihre hinterlegte Adresse lautet:</p>
                            <p class="mt-1 text-base font-semibold [overflow-wrap:anywhere] text-hvm-textschwarz">{{ auth()->user()?->email }}</p>
                        </div>
                    </div>
                </div>

                <div class="mt-8 flex flex-wrap gap-3">
                    <form method="POST" action="{{ route('verification.send') }}">
                        @csrf
                        <x-hvm.button type="submit" variant="primary">E-Mail erneut senden</x-hvm.button>
                    </form>

                    <x-hvm.button href="{{ route('portal.dashboard') }}" variant="secondary">
                        Weiter zur Übersicht
                        <x-hvm.icon name="arrow-right" class="h-4 w-4" />
                    </x-hvm.button>
                </div>
            </div>
        </x-hvm.card>

        <p class="mt-8 max-w-prose text-sm leading-relaxed text-hvm-text-sekundaer">
            Keine E-Mail erhalten? Prüfen Sie bitte auch den Spamordner. Sie können Ihre Adresse im Kontobereich
            ändern und den Link erneut anfordern.
        </p>
    </div>
@endsection
