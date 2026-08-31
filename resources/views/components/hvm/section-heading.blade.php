{{--
    Abschnittsueberschrift des HVM-Designsystems.

    Props:
      eyebrow  kurze Einordnung ueber der Ueberschrift
      title    Ueberschriftentext
      level    Ueberschriftenebene, Standard h2
      lead     einleitender Satz unter der Ueberschrift
      align    left oder center
--}}
@props([
    'title',
    'eyebrow' => null,
    'level' => 'h2',
    'lead' => null,
    'align' => 'left',
])

<div {{ $attributes->class([
    'max-w-3xl',
    'mx-auto text-center' => $align === 'center',
]) }}>
    @if ($eyebrow !== null)
        <p class="text-xs font-semibold tracking-widest text-hvm-textschwarz uppercase">
            <span class="mr-2 inline-block h-2 w-6 rounded-full bg-hvm-orange align-middle" aria-hidden="true"></span>
            {{ $eyebrow }}
        </p>
    @endif

    <{{ $level }} class="{{ $eyebrow !== null ? 'mt-3 ' : '' }}text-2xl font-bold text-hvm-anthrazit sm:text-3xl">{{ $title }}</{{ $level }}>

    @if ($lead !== null)
        <p class="mt-4 text-base leading-relaxed text-hvm-textschwarz sm:text-lg">{{ $lead }}</p>
    @endif

    @if ($slot->isNotEmpty())
        <div class="mt-4 text-base leading-relaxed text-hvm-textschwarz">
            {{ $slot }}
        </div>
    @endif
</div>
