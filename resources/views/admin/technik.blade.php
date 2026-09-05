{{--
    Technische Healthchecks.

    VERBINDLICH: Es wird niemals ein Secret ausgegeben, auch nicht teilweise
    maskiert. Angezeigt werden erreichbar ja oder nein, Version und
    Fehlerklasse.
--}}
@extends('layouts.admin')

@section('titel', 'Technik')

@section('content')
    <x-hvm.page-header
        eyebrow="Technik"
        title="Technische Healthchecks"
        lead="Angezeigt werden ausschließlich Erreichbarkeit, Version und Fehlerklasse. Zugangsdaten, Hosts und Pfade werden nicht angezeigt." />

    <div class="mt-10 grid grid-cols-1 gap-6 sm:grid-cols-2">
        @foreach ($proben as $probe)
            <x-hvm.card :title="$probe->name" eyebrow="Dienst" class="min-w-0">
                <p class="flex flex-wrap items-center gap-2">
                    <x-hvm.badge :variant="$probe->variant()">{{ $probe->statusLabel() }}</x-hvm.badge>
                    @if ($probe->version !== null)
                        <span class="text-sm text-hvm-text-sekundaer">Version {{ $probe->version }}</span>
                    @endif
                </p>
                @if ($probe->errorClass !== null)
                    <p class="mt-3 text-sm">Fehlerklasse: <span class="font-mono text-xs">{{ $probe->errorClass }}</span></p>
                @endif
                <p class="mt-3 max-w-prose text-sm leading-relaxed text-hvm-text-sekundaer">{{ $probe->note }}</p>
            </x-hvm.card>
        @endforeach
    </div>
@endsection
