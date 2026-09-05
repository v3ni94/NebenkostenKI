{{-- Supporteinblick in eine Organisation. Der Einblick ist protokolliert. --}}
@extends('layouts.admin')

@section('titel', 'Organisation')

@section('content')
    <x-hvm.page-header
        eyebrow="Supporteinblick"
        :title="$organisation->getAttribute('name')"
        :back="route('admin.organisationen')"
        back-label="Organisationen" />

    <div class="mt-8">
        <x-hvm.alert variant="info" label="Hinweis">
            Dieser Einblick erfolgt zu Supportzwecken und ist im Revisionsprotokoll vermerkt.
        </x-hvm.alert>
    </div>

    <div class="mt-10 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-hvm.card title="Objekte" eyebrow="Bestand" class="min-w-0">
            @if ($objekte === [])
                <p class="text-sm text-hvm-text-sekundaer">Kein Objekt.</p>
            @else
                <ul class="divide-y divide-hvm-linie">
                    @foreach ($objekte as $objekt)
                        <li class="py-2.5 text-sm first:pt-0 last:pb-0">
                            <a class="inline-flex min-h-11 items-center gap-2 font-medium text-hvm-textschwarz underline decoration-hvm-hellgrau underline-offset-4 hover:decoration-hvm-textschwarz" href="{{ route('admin.objekte.show', $objekt) }}">
                                <span>{{ $objekt->getAttribute('label') ?? $objekt->getKey() }}</span>
                                <x-hvm.icon name="arrow-right" class="h-4 w-4 text-hvm-text-sekundaer" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-hvm.card>

        <x-hvm.card title="Abrechnungsläufe" eyebrow="Vorgänge" class="min-w-0">
            @if ($laeufe === [])
                <p class="text-sm text-hvm-text-sekundaer">Kein Lauf.</p>
            @else
                <ul class="divide-y divide-hvm-linie">
                    @foreach ($laeufe as $lauf)
                        <li class="py-2.5 text-sm first:pt-0 last:pb-0">
                            <a class="inline-flex min-h-11 items-center gap-2 font-medium text-hvm-textschwarz underline decoration-hvm-hellgrau underline-offset-4 hover:decoration-hvm-textschwarz" href="{{ route('admin.abrechnungen.show', $lauf) }}">
                                <span>
                                    Abrechnungsjahr {{ $lauf->getAttribute('billing_year') }},
                                    Status {{ $lauf->getAttribute('status')->label() }}
                                </span>
                                <x-hvm.icon name="arrow-right" class="h-4 w-4 text-hvm-text-sekundaer" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-hvm.card>
    </div>
@endsection
