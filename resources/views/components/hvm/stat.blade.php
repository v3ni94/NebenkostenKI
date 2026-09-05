{{--
    Kennzahlkarte des HVM-Designsystems.

    Icon-Kachel in der Statusfarbe (Uebernahme aus Konzept B), Beschriftung
    und grosse Ziffer in Textschwarz. Der Status wird nie allein ueber die
    Farbe kommuniziert: die Beschriftung nennt die Kategorie, das Symbol ist
    je Variante fest zugeordnet (App\Support\Statussymbol: success
    check-circle, warning eye, info inbox, error alert). Die Beschriftung hat
    eine feste Mindesthoehe von zwei Zeilen, damit die Ziffern einer Reihe auf
    gleicher Hoehe stehen. Die Kennzahl bricht nie um (whitespace-nowrap);
    Betraege in schmalen Rastern brauchen deshalb grid-cols-1 sm:grid-cols-2.

    Props:
      label    Beschriftung (Pflicht)
      value    Kennzahl (Pflicht)
      variant  neutral, success, warning, error, info
      icon     Name eines x-hvm.icon, ueberschreibt das Standardsymbol;
               false unterdrueckt die Icon-Kachel
      note     kurzer Zusatztext unter der Kennzahl
      href     macht die gesamte Karte zum Link
      size     md (Standard: Ziffer text-3xl sm:text-5xl, Label zwei Zeilen)
               oder sm (Ziffer text-2xl sm:text-3xl, kleinerer Innenabstand,
               fuer Betraege in Vierer-Reihen und Zaehlreihen)
      tone     white (Standard, weisse Karte mit Linie) oder canvas (innere
               Hervorhebung ohne Rahmen, fuer Kachelreihen in einer Karte)
--}}
@props([
    'label',
    'value',
    'variant' => 'neutral',
    'icon' => null,
    'note' => null,
    'href' => null,
    'size' => 'md',
    'tone' => 'white',
])

@php
    $kachel = match ($variant) {
        'success' => 'bg-status-success-soft text-status-success',
        'warning' => 'bg-status-warning-soft text-status-warning',
        'error' => 'bg-status-error-soft text-status-error',
        'info' => 'bg-status-info-soft text-status-info',
        default => 'bg-hvm-canvas-deep text-hvm-text-sekundaer [.hvm-dark_&]:bg-hvm-graphit [.hvm-dark_&]:text-hvm-hellgrau',
    };

    $symbol = $icon ?? \App\Support\Statussymbol::fuer($variant) ?? 'grid';

    $flaeche = $tone === 'canvas'
        ? 'bg-hvm-canvas [.hvm-dark_&]:bg-hvm-graphit'
        : 'border border-hvm-linie bg-white [.hvm-dark_&]:border-hvm-graphit-soft [.hvm-dark_&]:bg-hvm-graphit-soft/40';
    $innen = $size === 'sm' ? 'p-4 sm:p-5' : 'p-5 sm:p-6';
    $klassen = 'flex min-w-0 flex-col rounded-2xl '.$flaeche.' '.$innen;
    $ziffer = $size === 'sm' ? 'text-2xl sm:text-3xl' : 'text-3xl sm:text-5xl';
    $beschriftung = $size === 'sm' ? 'text-xs leading-5' : 'min-h-10 text-sm leading-5';
    $kachelGroesse = $size === 'sm' ? 'h-8 w-8' : 'h-10 w-10';
    $symbolGroesse = $size === 'sm' ? 'h-4 w-4' : 'h-5 w-5';
    $element = $href !== null ? 'a' : 'div';
@endphp

<{{ $element }} @if ($href !== null) href="{{ $href }}" @endif {{ $attributes->class([$klassen, 'no-underline transition-colors hover:border-hvm-mittelgrau' => $href !== null]) }}>
    @if ($icon !== false)
        <span class="flex {{ $kachelGroesse }} items-center justify-center rounded-xl {{ $kachel }}" aria-hidden="true">
            <x-hvm.icon :name="$symbol" class="{{ $symbolGroesse }}" />
        </span>
    @endif
    <p class="{{ $icon !== false ? ($size === 'sm' ? 'mt-3' : 'mt-4') : '' }} {{ $beschriftung }} font-semibold hyphens-auto text-hvm-text-sekundaer [.hvm-dark_&]:text-hvm-hellgrau" lang="de">{{ $label }}</p>
    <p class="mt-1 {{ $ziffer }} font-semibold tracking-tight text-hvm-textschwarz tabular whitespace-nowrap [.hvm-dark_&]:text-white">{{ $value }}</p>
    @if ($note !== null)
        <p class="mt-2 text-sm text-hvm-text-sekundaer [.hvm-dark_&]:text-hvm-hellgrau">{{ $note }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-2 text-sm text-hvm-text-sekundaer [.hvm-dark_&]:text-hvm-hellgrau">{{ $slot }}</div>
    @endif
</{{ $element }}>
