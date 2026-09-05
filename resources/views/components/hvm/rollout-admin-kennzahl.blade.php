{{--
    Kompakte Zaehlkachel fuer Statuszahlen im Adminbereich.

    Fuer Reihen mit vielen Werten (Zaehler je Status, Tageskosten, Vorlagen),
    bei denen x-hvm.stat zu gross waere. Innere Hervorhebung nach Muster 4.5
    des Designsystems: Canvas-Flaeche in einer weissen Karte.

    Props:
      label  Bezeichnung (Pflicht)
--}}
@props([
    'label',
])

<div {{ $attributes->class('min-w-0 rounded-2xl bg-hvm-canvas p-4 [.hvm-dark_&]:bg-hvm-graphit') }}>
    <dt class="text-xs leading-5 font-semibold break-words text-hvm-text-sekundaer [.hvm-dark_&]:text-hvm-hellgrau">{{ $label }}</dt>
    <dd class="mt-1 text-2xl font-semibold tracking-tight text-hvm-textschwarz tabular break-normal [.hvm-dark_&]:text-white">{{ $slot }}</dd>
</div>
