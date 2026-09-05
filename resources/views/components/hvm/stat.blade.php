{{--
    Kennzahlkarte des HVM-Designsystems (Konzept A).

    Grosse Ziffer, Beschriftung, optional Statuspunkt mit Text. Der Status
    wird nie allein ueber die Farbe kommuniziert: die Beschriftung nennt die
    Kategorie, der Punkt ist nur zusaetzliche Information.

    Props:
      label    Beschriftung (Pflicht)
      value    Kennzahl (Pflicht)
      variant  neutral, success, warning, error, info (Statuspunkt)
      note     kurzer Zusatztext unter der Kennzahl
      href     macht die gesamte Karte zum Link
--}}
@props([
    'label',
    'value',
    'variant' => 'neutral',
    'note' => null,
    'href' => null,
])

@php
    $punkt = match ($variant) {
        'success' => 'bg-status-success',
        'warning' => 'bg-status-warning',
        'error' => 'bg-status-error',
        'info' => 'bg-status-info',
        default => 'bg-hvm-mittelgrau',
    };

    $klassen = 'block rounded-2xl border border-hvm-linie bg-white p-5 sm:p-6';
    $element = $href !== null ? 'a' : 'div';
@endphp

<{{ $element }} @if ($href !== null) href="{{ $href }}" @endif {{ $attributes->class([$klassen, 'no-underline transition-colors hover:border-hvm-mittelgrau' => $href !== null]) }}>
    <p class="flex items-center gap-2 text-sm font-semibold text-hvm-text-sekundaer">
        <span class="h-2 w-2 shrink-0 rounded-full {{ $punkt }}" aria-hidden="true"></span>
        <span class="min-w-0">{{ $label }}</span>
    </p>
    <p class="mt-3 text-3xl font-semibold tracking-tight text-hvm-textschwarz tabular sm:text-5xl">{{ $value }}</p>
    @if ($note !== null)
        <p class="mt-2 text-sm text-hvm-text-sekundaer">{{ $note }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-2 text-sm text-hvm-text-sekundaer">{{ $slot }}</div>
    @endif
</{{ $element }}>
