{{--
    Aufklappbare Frage des HVM-Designsystems (Konzept A).

    Umsetzung mit Alpine.js. Die Schaltflaeche ist vollstaendig
    tastaturbedienbar und fuehrt aria-expanded sowie aria-controls. Ohne
    JavaScript bleibt die Antwort dauerhaft sichtbar, damit kein Inhalt
    verloren geht.

    Props:
      question  Fragetext
      open      true klappt die Antwort beim Laden auf
      level     Ueberschriftenebene der Frage, Standard h3
--}}
@props([
    'question',
    'open' => false,
    'level' => 'h3',
])

@php
    $panelId = 'faq-antwort-'.substr(md5($question), 0, 10);
@endphp

<div class="border-b border-hvm-linie" x-data="{ offen: {{ $open ? 'true' : 'false' }} }">
    <{{ $level }} class="m-0">
        <button type="button"
                class="group flex w-full min-h-11 items-center justify-between gap-4 rounded-lg py-5 text-left text-base font-semibold tracking-tight text-hvm-textschwarz transition-colors hover:bg-hvm-canvas-deep sm:text-lg -mx-2 px-2 w-[calc(100%+1rem)]"
                x-on:click="offen = !offen"
                x-bind:aria-expanded="offen ? 'true' : 'false'"
                aria-expanded="{{ $open ? 'true' : 'false' }}"
                aria-controls="{{ $panelId }}">
            <span>{{ $question }}</span>
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-hvm-linie bg-white text-hvm-text-sekundaer group-hover:border-hvm-orange group-hover:text-hvm-orange-dark" aria-hidden="true">
                <svg class="h-4 w-4 transition-transform" viewBox="0 0 20 20" fill="currentColor"
                     x-bind:class="offen ? 'rotate-180' : ''">
                    <path d="M5.3 7.3a1 1 0 0 1 1.4 0L10 10.6l3.3-3.3a1 1 0 1 1 1.4 1.4l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 0 1 0-1.4Z" />
                </svg>
            </span>
        </button>
    </{{ $level }}>

    <div id="{{ $panelId }}"
         class="max-w-prose pb-6 text-base leading-relaxed text-hvm-textschwarz"
         x-show="offen"
         x-cloak>
        {{ $slot }}
    </div>
</div>
