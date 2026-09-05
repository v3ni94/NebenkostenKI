{{--
    Abschnittsueberschrift des HVM-Designsystems (Konzept A).

    Props:
      eyebrow  kurze Einordnung ueber der Ueberschrift (mit orangem Strich)
      title    Ueberschriftentext
      level    Ueberschriftenebene, Standard h2
      lead     einleitender Satz unter der Ueberschrift
      align    left oder center
      size     md (Abschnitt, Standard), lg (Seitenkopf), sm (Unterabschnitt)
--}}
@props([
    'title',
    'eyebrow' => null,
    'level' => 'h2',
    'lead' => null,
    'align' => 'left',
    'size' => 'md',
])

@php
    $titelgroesse = match ($size) {
        'lg' => 'text-4xl sm:text-5xl lg:text-6xl',
        'sm' => 'text-xl sm:text-2xl',
        default => 'text-3xl sm:text-4xl',
    };

    $leadgroesse = match ($size) {
        'lg' => 'mt-6 text-lg sm:text-xl',
        'sm' => 'mt-2 text-base',
        default => 'mt-4 text-base sm:text-lg',
    };
@endphp

<div {{ $attributes->class([
    'max-w-3xl',
    'mx-auto text-center' => $align === 'center',
]) }}>
    @if ($eyebrow !== null)
        <p class="flex items-center gap-3 text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase {{ $align === 'center' ? 'justify-center' : '' }}">
            <span class="inline-block h-px w-8 bg-hvm-orange" aria-hidden="true"></span>
            {{ $eyebrow }}
        </p>
    @endif

    <{{ $level }} class="{{ $eyebrow !== null ? 'mt-4 ' : '' }}{{ $titelgroesse }} font-semibold tracking-tight text-hvm-textschwarz">{{ $title }}</{{ $level }}>

    @if ($lead !== null)
        <p class="{{ $leadgroesse }} max-w-prose leading-relaxed text-hvm-text-sekundaer {{ $align === 'center' ? 'mx-auto' : '' }}">{{ $lead }}</p>
    @endif

    @if ($slot->isNotEmpty())
        <div class="mt-4 max-w-prose text-base leading-relaxed text-hvm-textschwarz {{ $align === 'center' ? 'mx-auto' : '' }}">
            {{ $slot }}
        </div>
    @endif
</div>
