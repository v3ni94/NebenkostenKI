@extends('layouts.portal')

@section('titel', 'Abrechnungen')

@section('content')
    <x-hvm.page-header
        eyebrow="Laufende Vorgänge"
        title="Abrechnungen"
        lead="Jeder Abrechnungslauf gehört zu einem Objekt und einem Abrechnungszeitraum.">
        @if ($laeufe !== [])
            <x-slot:actions>
                <x-hvm.button href="{{ route('portal.abrechnungen.create') }}" variant="primary">
                    <x-hvm.icon name="plus" class="h-4 w-4" />
                    Neue Abrechnung
                </x-hvm.button>
            </x-slot:actions>
        @endif
    </x-hvm.page-header>

    @if ($laeufe === [])
        {{-- Im Leerzustand traegt allein der Leerzustand die Handlung (4.11), kein zweiter Button im Seitenkopf. --}}
        <x-hvm.empty-state class="mt-10" icon="document" title="Noch keine Abrechnung">
            <p>Es ist noch keine Abrechnung angelegt.</p>
            <x-slot:action>
                <x-hvm.button href="{{ route('portal.abrechnungen.create') }}" variant="primary">Neue Abrechnung</x-hvm.button>
            </x-slot:action>
        </x-hvm.empty-state>
    @else
        <x-hvm.card class="mt-10 divide-y divide-hvm-linie" padding="none">
            @foreach ($laeufe as $lauf)
                @php
                    $laufTitel = 'Abrechnung '.$lauf->billing_year.($lauf->property !== null ? ' für '.$lauf->property->label : '');
                    $laufZeitraum = 'Zeitraum '.$lauf->period_start?->format('d.m.Y').' bis '.$lauf->period_end?->format('d.m.Y').', '.$lauf->mode->label();
                @endphp
                <x-hvm.list-row
                    :title="$laufTitel"
                    :href="route('portal.abrechnungen.show', ['billingRun' => $lauf->getKey()])"
                    :subtitle="$laufZeitraum">
                    @include('portal.partials.status', ['status' => $hinweise[$lauf->getKey()]])

                    <x-slot:actions>
                        <x-hvm.button href="{{ route('portal.abrechnungen.show', ['billingRun' => $lauf->getKey()]) }}"
                                      variant="secondary" size="sm">
                            Öffnen
                            <x-hvm.icon name="arrow-right" class="h-4 w-4" />
                        </x-hvm.button>
                    </x-slot:actions>
                </x-hvm.list-row>
            @endforeach
        </x-hvm.card>
    @endif
@endsection
