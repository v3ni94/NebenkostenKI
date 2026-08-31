{{--
    Schaltflaeche des HVM-Designsystems.

    Varianten:
      primary    HVM Orange, nur fuer die jeweils wichtigste Handlung
      secondary  neutrale Umrissflaeche
      ghost      textnahe Handlung ohne Flaeche

    Wird href gesetzt, rendert die Komponente einen Link (as-link). Die
    Mindesthoehe von 44 Pixeln sichert die Bedienbarkeit auf Touchgeraeten.
--}}
@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
])

@php
    $basis = 'inline-flex items-center justify-center gap-2 rounded-md font-semibold no-underline transition-colors';

    $groesse = match ($size) {
        'sm' => 'min-h-11 px-4 py-2 text-sm',
        'lg' => 'min-h-12 px-7 py-3 text-base',
        default => 'min-h-11 px-5 py-2.5 text-base',
    };

    $farben = match ($variant) {
        'secondary' => 'border border-hvm-anthrazit bg-white text-hvm-textschwarz hover:bg-hvm-umrissgrau',
        'ghost' => 'border border-transparent text-hvm-textschwarz underline underline-offset-4 hover:bg-hvm-umrissgrau',
        default => 'border border-hvm-orange-dark bg-hvm-orange text-hvm-textschwarz hover:bg-hvm-orange-dark',
    };

    $klassen = trim($basis.' '.$groesse.' '.$farben);
@endphp

@if ($href !== null)
    <a href="{{ $href }}" {{ $attributes->class($klassen) }}>
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->class($klassen) }}>
        {{ $slot }}
    </button>
@endif
