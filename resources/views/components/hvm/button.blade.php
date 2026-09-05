{{--
    Schaltflaeche des HVM-Designsystems.

    Varianten:
      primary    HVM Orange, nur fuer die jeweils wichtigste Handlung (genau
                 eine je Ansicht); wirkt auf hellen und dunklen Flaechen gleich
      secondary  weisse Flaeche mit hauchduenner Linie; auf .hvm-dark
                 automatisch transparent mit weissem Rahmen
      ghost      textnahe Handlung ohne Flaeche; auf .hvm-dark weiss
      danger     destruktive Handlung (Entfernen, Loeschen): weisse Flaeche,
                 Rahmen und Text in der Fehlerfarbe, nie als Textlink
      dark       Textschwarz mit weisser Schrift, zweiter starker Akzent auf
                 hellen Flaechen
      inverse    weisse Flaeche mit Textschwarz, fuer dunkle Flaechen

    Groessen: sm (44 px), md (48 px), lg (56 px). Alle Groessen erfuellen die
    Mindesthoehe fuer Touchziele. Wird href gesetzt, rendert die Komponente
    einen Link. Schaltflaechen sind Pills (rounded-full). Unter sm duerfen
    lange Beschriftungen umbrechen, damit ein Button den 390-px-Viewport nie
    aufweitet; ab sm bleibt die Beschriftung einzeilig.
--}}
@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
])

@php
    $basis = 'inline-flex items-center justify-center gap-2 rounded-full text-center font-semibold no-underline sm:whitespace-nowrap transition-colors duration-150 disabled:cursor-not-allowed disabled:opacity-50';

    $groesse = match ($size) {
        'sm' => 'min-h-11 px-4 py-2 text-sm',
        'lg' => 'min-h-14 px-8 py-3.5 text-base',
        default => 'min-h-12 px-6 py-3 text-base',
    };

    $farben = match ($variant) {
        'secondary' => 'border border-hvm-hellgrau bg-white text-hvm-textschwarz hover:border-hvm-mittelgrau hover:bg-hvm-canvas [.hvm-dark_&]:border-white/60 [.hvm-dark_&]:bg-transparent [.hvm-dark_&]:text-white [.hvm-dark_&]:hover:border-white [.hvm-dark_&]:hover:bg-white/10',
        'ghost' => 'border border-transparent text-hvm-textschwarz underline decoration-hvm-hellgrau underline-offset-4 hover:bg-hvm-canvas-deep hover:decoration-hvm-textschwarz [.hvm-dark_&]:text-white [.hvm-dark_&]:decoration-white/40 [.hvm-dark_&]:hover:bg-white/10 [.hvm-dark_&]:hover:decoration-white',
        'danger' => 'border border-status-error/40 bg-white text-status-error hover:border-status-error hover:bg-status-error-soft [.hvm-dark_&]:bg-transparent [.hvm-dark_&]:border-status-error-soft/60 [.hvm-dark_&]:text-status-error-soft [.hvm-dark_&]:hover:bg-white/10',
        'dark' => 'border border-hvm-textschwarz bg-hvm-textschwarz text-white hover:bg-hvm-graphit-soft [.hvm-dark_&]:border-white [.hvm-dark_&]:bg-white [.hvm-dark_&]:text-hvm-textschwarz [.hvm-dark_&]:hover:bg-hvm-hellgrau',
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
