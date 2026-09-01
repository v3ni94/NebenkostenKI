{{-- Supporteinblick in ein Objekt. Der Einblick ist protokolliert. --}}
@extends('layouts.admin')

@section('titel', 'Objekt')

@section('content')
    <x-hvm.section-heading level="h1" title="Objekt" />

    <div class="mt-6">
        <x-hvm.alert variant="info" label="Hinweis">
            Dieser Einblick erfolgt zu Supportzwecken und ist im Revisionsprotokoll vermerkt.
        </x-hvm.alert>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <x-hvm.card title="Stammdaten">
            <dl class="space-y-1 text-sm">
                <div class="flex justify-between"><dt>Kennung</dt><dd class="font-mono text-xs">{{ $objekt->getKey() }}</dd></div>
                <div class="flex justify-between"><dt>Art</dt><dd>{{ $objekt->getAttribute('kind')->label() }}</dd></div>
                <div class="flex justify-between"><dt>Ort</dt><dd>{{ $objekt->getAttribute('city') }}</dd></div>
            </dl>
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
