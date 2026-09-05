{{--
    Schaltflaeche des HVM-Designsystems (Konzept A).

    Varianten:
      primary    HVM Orange, nur fuer die jeweils wichtigste Handlung
      secondary  weisse Flaeche mit hauchduenner Linie
      ghost      textnahe Handlung ohne Flaeche
      dark       Textschwarz mit weisser Schrift, fuer helle Flaechen mit
                 zweitem starken Akzent oder als Primaerknopf auf Graphit
      inverse    weisse Flaeche auf dunklen Flaechen (Graphit)

    Groessen: sm (44 px), md (48 px), lg (56 px). Alle Groessen erfuellen die
    Mindesthoehe fuer Touchziele. Wird href gesetzt, rendert die Komponente
    einen Link. Schaltflaechen sind Pills (rounded-full).
--}}
@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
])

@php
    $basis = 'inline-flex items-center justify-center gap-2 rounded-full font-semibold whitespace-nowrap no-underline transition-colors duration-150 disabled:cursor-not-allowed disabled:opacity-50';

    $groesse = match ($size) {
        'sm' => 'min-h-11 px-4 py-2 text-sm',
        'lg' => 'min-h-14 px-8 py-3.5 text-base',
        default => 'min-h-12 px-6 py-3 text-base',
    };

    $farben = match ($variant) {
        'secondary' => 'border border-hvm-hellgrau bg-white text-hvm-textschwarz hover:border-hvm-mittelgrau hover:bg-hvm-canvas',
        'ghost' => 'border border-transparent text-hvm-textschwarz underline decoration-hvm-hellgrau underline-offset-4 hover:bg-hvm-canvas-deep hover:decoration-hvm-textschwarz',
        'dark' => 'border border-hvm-textschwarz bg-hvm-textschwarz text-white hover:bg-hvm-graphit-soft',
        'inverse' => 'border border-white bg-white text-hvm-textschwarz hover:bg-hvm-hellgrau',
        default => 'border border-hvm-orange bg-hvm-orange text-hvm-textschwarz hover:border-hvm-orange-dark hover:bg-hvm-orange-dark',
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
