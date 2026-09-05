{{--
    Abschnittsueberschrift des HVM-Designsystems.

    Innerhalb einer .hvm-dark-Flaeche wechseln Titel auf Weiss und Eyebrow
    sowie Lead auf Hellgrau, ohne dass eine Variante gesetzt werden muss.

    Props:
      eyebrow  kurze Einordnung ueber der Ueberschrift (mit orangem Strich)
      title    Ueberschriftentext. Ein &shy; im Text wird als weicher
               Trennstrich (U+00AD) ausgegeben, alles andere bleibt escaped.
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
    $titel = str_replace('&shy;', "\u{00AD}", $title);

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
        <p class="flex items-center gap-3 text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase [.hvm-dark_&]:text-hvm-hellgrau {{ $align === 'center' ? 'justify-center' : '' }}">
            <span class="inline-block h-px w-8 bg-hvm-orange" aria-hidden="true"></span>
            {{ $eyebrow }}
        </p>
    @endif

    <{{ $level }} class="{{ $eyebrow !== null ? 'mt-4 ' : '' }}{{ $titelgroesse }} font-semibold tracking-tight text-hvm-textschwarz [.hvm-dark_&]:text-white">{{ $titel }}</{{ $level }}>

    @if ($lead !== null)
        <p class="{{ $leadgroesse }} max-w-prose leading-relaxed text-hvm-text-sekundaer [.hvm-dark_&]:text-hvm-hellgrau {{ $align === 'center' ? 'mx-auto' : '' }}">{{ $lead }}</p>
    @endif

    @if ($slot->isNotEmpty())
        <div class="mt-4 max-w-prose text-base leading-relaxed text-hvm-textschwarz [.hvm-dark_&]:text-hvm-hellgrau {{ $align === 'center' ? 'mx-auto' : '' }}">
            {{ $slot }}
        </div>
    @endif
</div>
