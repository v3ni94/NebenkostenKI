{{--
    Inhaltskarte des HVM-Designsystems.

    Optional:
      title      Ueberschrift der Karte
      level      Ueberschriftenebene, Standard h3
      eyebrow    kurze Einordnung ueber der Ueberschrift
      accent     true setzt eine dezente orange Akzentlinie oben
--}}
@props([
    'title' => null,
    'level' => 'h3',
    'eyebrow' => null,
    'accent' => false,
])

<div {{ $attributes->class([
    'rounded-lg border border-hvm-hellgrau bg-white p-6',
    'border-t-4 border-t-hvm-orange' => $accent,
]) }}>
    @if ($eyebrow !== null)
        <p class="text-xs font-semibold tracking-wide text-hvm-textschwarz uppercase">{{ $eyebrow }}</p>
    @endif

    @if ($title !== null)
        <{{ $level }} class="{{ $eyebrow !== null ? 'mt-2 ' : '' }}text-lg font-semibold text-hvm-anthrazit">{{ $title }}</{{ $level }}>
    @endif

    <div class="{{ $title !== null || $eyebrow !== null ? 'mt-3 ' : '' }}text-base leading-relaxed text-hvm-textschwarz">
        {{ $slot }}
    </div>
</div>
