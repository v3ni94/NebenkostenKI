@extends('layouts.portal')

@section('titel', 'Abrechnungen')

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-3">
        <x-hvm.section-heading
            title="Abrechnungen"
            lead="Jeder Abrechnungslauf gehört zu einem Objekt und einem Abrechnungszeitraum." />
        <x-hvm.button href="{{ route('portal.abrechnungen.create') }}" variant="primary">Neue Abrechnung</x-hvm.button>
    </div>

    @if ($laeufe === [])
        <x-hvm.card class="mt-8">
            <p>Es ist noch keine Abrechnung angelegt.</p>
        </x-hvm.card>
    @else
        <div class="mt-8 space-y-4">
            @foreach ($laeufe as $lauf)
                <x-hvm.card>
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <h3 class="text-lg font-semibold text-hvm-anthrazit">
                                Abrechnung {{ $lauf->billing_year }}
                                @if ($lauf->property !== null)
                                    für {{ $lauf->property->label }}
                                @endif
                            </h3>
                            <p class="mt-1 text-sm text-hvm-textschwarz">
                                Zeitraum {{ $lauf->period_start?->format('d.m.Y') }}
                                bis {{ $lauf->period_end?->format('d.m.Y') }},
                                {{ $lauf->mode->label() }}
                            </p>
                            <div class="mt-3">
                                @include('portal.partials.status', ['status' => $hinweise[$lauf->getKey()]])
                            </div>
                        </div>

                        <x-hvm.button href="{{ route('portal.abrechnungen.show', ['billingRun' => $lauf->getKey()]) }}"
                                      variant="secondary" size="sm">Öffnen</x-hvm.button>
                    </div>
                </x-hvm.card>
            @endforeach
        </div>
    @endif
@endsection
