{{--
    Inhaltskarte des HVM-Designsystems.

    Grosse Radien, hauchduenne Linie, kein Schatten. Tiefe entsteht durch den
    Wechsel der Flaeche gegen den Hintergrund (Weiss auf Canvas, Canvas auf
    Weiss). Innerhalb einer .hvm-dark-Flaeche wird die helle Karte automatisch
    zu einer Graphit-soft-Karte mit hellem Text; tone="dark" macht die Karte
    selbst zur dunklen Flaeche (setzt .hvm-dark), sodass alle Komponenten in
    ihr sich anpassen.

    Optional:
      title      Ueberschrift der Karte
      level      Ueberschriftenebene, Standard h3
      eyebrow    kurze Einordnung ueber der Ueberschrift
      accent     true setzt eine kurze orange Akzentlinie ueber den Inhalt
      tone       white (Standard), canvas, dark
      padding    md (Standard), sm, none (fuer Listen und Tabellen mit eigenen
                 Zeilen; ein title oder eyebrow bildet dann einen Kartenkopf
                 mit Innenabstand und Trennlinie, der Slot wird direkt gerendert)
      kennlinie  true setzt die HVM-Kennlinie als obere Kartenkante
--}}
@props([
    'title' => null,
    'level' => 'h3',
    'eyebrow' => null,
    'accent' => false,
    'tone' => 'white',
    'padding' => 'md',
    'kennlinie' => false,
])

@php
    $dunkelAuto = '[.hvm-dark_&]:border-hvm-graphit-soft [.hvm-dark_&]:bg-hvm-graphit-soft/40 [.hvm-dark_&]:text-hvm-hellgrau';

    $flaeche = match ($tone) {
        'canvas' => 'border-hvm-linie bg-hvm-canvas '.$dunkelAuto,
        'dark' => 'hvm-dark border-hvm-graphit-soft',
        default => 'border-hvm-linie bg-white '.$dunkelAuto,
    };

    $innen = match ($padding) {
        'none' => '',
        'sm' => 'p-4 sm:p-5',
        default => 'p-6 sm:p-7',
    };

    $kartenkopf = $padding === 'none' && ($title !== null || $eyebrow !== null || $accent);

    $titelfarbe = 'text-hvm-textschwarz [.hvm-dark_&]:text-white';
    $textfarbe = 'text-hvm-textschwarz [.hvm-dark_&]:text-hvm-hellgrau';
    $eyebrowfarbe = 'text-hvm-text-sekundaer [.hvm-dark_&]:text-hvm-hellgrau';
@endphp

<div {{ $attributes->class(['rounded-2xl border', 'overflow-hidden' => $kennlinie, $flaeche, $innen => ! $kennlinie]) }}>
    @if ($kennlinie)
        <div class="hvm-kennlinie" aria-hidden="true"></div>
        <div class="{{ $innen }}">
    @endif

    @if ($kartenkopf)
        <div class="border-b border-hvm-linie p-6 sm:p-7 [.hvm-dark_&]:border-hvm-graphit-soft">
    @endif

    @if ($accent)
        <span class="mb-5 block h-1 w-10 rounded-full bg-hvm-orange" aria-hidden="true"></span>
    @endif

    @if ($eyebrow !== null)
        <p class="text-xs font-semibold tracking-[0.08em] uppercase {{ $eyebrowfarbe }}">{{ $eyebrow }}</p>
    @endif

    @if ($title !== null)
        <{{ $level }} class="{{ $eyebrow !== null ? 'mt-2 ' : '' }}text-lg font-semibold tracking-tight {{ $titelfarbe }} sm:text-xl">{{ $title }}</{{ $level }}>
    @endif

    @if ($kartenkopf)
        </div>
    @endif

    @if ($padding === 'none')
        {{ $slot }}
    @else
        <div class="{{ $title !== null || $eyebrow !== null ? 'mt-3 ' : '' }}text-base leading-relaxed {{ $textfarbe }}">
            {{ $slot }}
        </div>
    @endif

    @if ($kennlinie)
        </div>
    @endif
</div>
