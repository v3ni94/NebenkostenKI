{{--
    Kennzahlkarte des HVM-Designsystems.

    Icon-Kachel in der Statusfarbe (Uebernahme aus Konzept B), Beschriftung
    und grosse Ziffer in Textschwarz. Der Status wird nie allein ueber die
    Farbe kommuniziert: die Beschriftung nennt die Kategorie, das Symbol ist
    je Variante fest zugeordnet (success check-circle, warning eye, info inbox,
    error alert). Die Beschriftung hat eine feste Mindesthoehe von zwei Zeilen,
    damit die Ziffern einer Reihe auf gleicher Hoehe stehen.

    Props:
      label    Beschriftung (Pflicht)
      value    Kennzahl (Pflicht)
      variant  neutral, success, warning, error, info
      icon     Name eines x-hvm.icon, ueberschreibt das Standardsymbol
      note     kurzer Zusatztext unter der Kennzahl
      href     macht die gesamte Karte zum Link
--}}
@props([
    'label',
    'value',
    'variant' => 'neutral',
    'icon' => null,
    'note' => null,
    'href' => null,
])

@php
    $kachel = match ($variant) {
        'success' => 'bg-status-success-soft text-status-success',
        'warning' => 'bg-status-warning-soft text-status-warning',
        'error' => 'bg-status-error-soft text-status-error',
        'info' => 'bg-status-info-soft text-status-info',
        default => 'bg-hvm-canvas-deep text-hvm-text-sekundaer [.hvm-dark_&]:bg-hvm-graphit [.hvm-dark_&]:text-hvm-hellgrau',
    };

    $symbol = $icon ?? match ($variant) {
        'success' => 'check-circle',
        'warning' => 'eye',
        'error' => 'alert',
        'info' => 'inbox',
        default => 'grid',
    };

    $klassen = 'block rounded-2xl border border-hvm-linie bg-white p-5 sm:p-6 [.hvm-dark_&]:border-hvm-graphit-soft [.hvm-dark_&]:bg-hvm-graphit-soft/40';
    $element = $href !== null ? 'a' : 'div';
@endphp

<{{ $element }} @if ($href !== null) href="{{ $href }}" @endif {{ $attributes->class([$klassen, 'no-underline transition-colors hover:border-hvm-mittelgrau' => $href !== null]) }}>
    <span class="flex h-10 w-10 items-center justify-center rounded-xl {{ $kachel }}" aria-hidden="true">
        <x-hvm.icon :name="$symbol" class="h-5 w-5" />
    </span>
    <p class="mt-4 min-h-10 text-sm leading-5 font-semibold text-hvm-text-sekundaer [.hvm-dark_&]:text-hvm-hellgrau">{{ $label }}</p>
    <p class="mt-1 text-3xl font-semibold tracking-tight text-hvm-textschwarz tabular sm:text-5xl [.hvm-dark_&]:text-white">{{ $value }}</p>
    @if ($note !== null)
        <p class="mt-2 text-sm text-hvm-text-sekundaer [.hvm-dark_&]:text-hvm-hellgrau">{{ $note }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-2 text-sm text-hvm-text-sekundaer [.hvm-dark_&]:text-hvm-hellgrau">{{ $slot }}</div>
    @endif
</{{ $element }}>
