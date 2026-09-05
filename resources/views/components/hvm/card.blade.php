{{--
    Inhaltskarte des HVM-Designsystems (Konzept A).

    Grosse Radien, hauchduenne Linie, kein Schatten. Tiefe entsteht durch den
    Wechsel der Flaeche gegen den Hintergrund (Weiss auf Canvas, Canvas auf
    Weiss).

    Optional:
      title      Ueberschrift der Karte
      level      Ueberschriftenebene, Standard h3
      eyebrow    kurze Einordnung ueber der Ueberschrift
      accent     true setzt eine kurze orange Akzentlinie ueber den Inhalt
      tone       white (Standard), canvas, dark
      padding    md (Standard), sm, none (fuer Listen mit eigenen Zeilen)
--}}
@props([
    'title' => null,
    'level' => 'h3',
    'eyebrow' => null,
    'accent' => false,
    'tone' => 'white',
    'padding' => 'md',
])

@php
    $flaeche = match ($tone) {
        'canvas' => 'border-hvm-linie bg-hvm-canvas',
        'dark' => 'border-hvm-graphit-soft bg-hvm-graphit text-white',
        default => 'border-hvm-linie bg-white',
    };

    $innen = match ($padding) {
        'none' => '',
        'sm' => 'p-4 sm:p-5',
        default => 'p-6 sm:p-7',
    };

    $titelfarbe = $tone === 'dark' ? 'text-white' : 'text-hvm-textschwarz';
    $textfarbe = $tone === 'dark' ? 'text-hvm-hellgrau' : 'text-hvm-textschwarz';
    $eyebrowfarbe = $tone === 'dark' ? 'text-hvm-hellgrau' : 'text-hvm-text-sekundaer';
@endphp

<div {{ $attributes->class(['rounded-2xl border', $flaeche, $innen]) }}>
    @if ($accent)
        <span class="mb-5 block h-1 w-10 rounded-full bg-hvm-orange" aria-hidden="true"></span>
    @endif

    @if ($eyebrow !== null)
        <p class="text-xs font-semibold tracking-[0.08em] uppercase {{ $eyebrowfarbe }}">{{ $eyebrow }}</p>
    @endif

    @if ($title !== null)
        <{{ $level }} class="{{ $eyebrow !== null ? 'mt-2 ' : '' }}text-lg font-semibold tracking-tight {{ $titelfarbe }} sm:text-xl">{{ $title }}</{{ $level }}>
    @endif

    <div class="{{ $title !== null || $eyebrow !== null ? 'mt-3 ' : '' }}text-base leading-relaxed {{ $textfarbe }}">
        {{ $slot }}
    </div>
</div>
