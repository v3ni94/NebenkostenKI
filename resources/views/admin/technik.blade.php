{{--
    Technische Healthchecks.

    VERBINDLICH: Es wird niemals ein Secret ausgegeben, auch nicht teilweise
    maskiert. Angezeigt werden erreichbar ja oder nein, Version und
    Fehlerklasse.
--}}
@extends('layouts.admin')

@section('titel', 'Technik')

@section('content')
    <x-hvm.section-heading
        level="h1"
        title="Technische Healthchecks"
        lead="Angezeigt werden ausschließlich Erreichbarkeit, Version und Fehlerklasse. Zugangsdaten, Hosts und Pfade werden nicht angezeigt." />

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        @foreach ($proben as $probe)
            <x-hvm.card :title="$probe->name">
                <p class="flex flex-wrap items-center gap-2">
                    <x-hvm.badge :variant="$probe->variant()">{{ $probe->statusLabel() }}</x-hvm.badge>
                    @if ($probe->version !== null)
                        <span class="text-sm">Version {{ $probe->version }}</span>
                    @endif
                </p>
                @if ($probe->errorClass !== null)
                    <p class="mt-2 text-sm">Fehlerklasse: {{ $probe->errorClass }}</p>
                @endif
                <p class="mt-2 text-sm">{{ $probe->note }}</p>
            </x-hvm.card>
        @endforeach
    </div>
@endsection
