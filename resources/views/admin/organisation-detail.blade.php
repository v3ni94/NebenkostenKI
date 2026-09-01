{{-- Supporteinblick in eine Organisation. Der Einblick ist protokolliert. --}}
@extends('layouts.admin')

@section('titel', 'Organisation')

@section('content')
    <x-hvm.section-heading level="h1" title="{{ $organisation->getAttribute('name') }}" />

    <div class="mt-6">
        <x-hvm.alert variant="info" label="Hinweis">
            Dieser Einblick erfolgt zu Supportzwecken und ist im Revisionsprotokoll vermerkt.
        </x-hvm.alert>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <x-hvm.card title="Objekte">
            @if ($objekte === [])
                <p>Kein Objekt.</p>
            @else
                <ul class="space-y-2 text-sm">
                    @foreach ($objekte as $objekt)
                        <li>
                            <a class="underline underline-offset-2" href="{{ route('admin.objekte.show', $objekt) }}">
                                {{ $objekt->getAttribute('label') ?? $objekt->getKey() }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-hvm.card>

        <x-hvm.card title="Abrechnungsläufe">
            @if ($laeufe === [])
                <p>Kein Lauf.</p>
            @else
                <ul class="space-y-2 text-sm">
                    @foreach ($laeufe as $lauf)
                        <li>
                            <a class="underline underline-offset-2" href="{{ route('admin.abrechnungen.show', $lauf) }}">
                                Abrechnungsjahr {{ $lauf->getAttribute('billing_year') }},
                                Status {{ $lauf->getAttribute('status')->label() }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-hvm.card>
    </div>
@endsection
