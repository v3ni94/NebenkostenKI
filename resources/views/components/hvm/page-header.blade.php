{{--
    Seitenkopf der Anwendung (Portal, Wizard, Admin).

    Titel links, Handlungen rechts, optionale Einordnung (Eyebrow), Einleitung
    (Lead) und ein Slot fuer Zusatzinhalt unter dem Lead (z. B. Badge, Meta-
    Zeile). Ersetzt das Muster "section-heading plus Button in einer Flexbox".

    Props:
      title    Seitentitel (h1)
      eyebrow  kurze Einordnung, z. B. Bereich oder "Schritt 3 von 12"
      lead     einleitender Satz
      size     md (Standard, Portal), lg (grosse Seitenkoepfe), sm (Dialoge)
      back     URL eines Zurueck-Links ueber dem Titel (optional)
      backLabel  Beschriftung des Zurueck-Links, Standard "Zurück"

    Slots:
      default  Zusatzinhalt unter dem Lead
      actions  Schaltflaechen rechts (genau ein primary)
--}}
@props([
    'title',
    'eyebrow' => null,
    'lead' => null,
    'size' => 'md',
    'back' => null,
    'backLabel' => 'Zurück',
])

<div {{ $attributes->class('flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between') }}>
    <div class="min-w-0">
        @if ($back !== null)
            <a href="{{ $back }}" class="mb-4 inline-flex min-h-11 items-center gap-1.5 text-sm font-medium text-hvm-text-sekundaer no-underline hover:text-hvm-textschwarz hover:underline underline-offset-4 [.hvm-dark_&]:text-hvm-hellgrau [.hvm-dark_&]:hover:text-white">
                <x-hvm.icon name="arrow-right" class="h-4 w-4 rotate-180" />
                {{ $backLabel }}
            </a>
        @endif

        <x-hvm.section-heading :title="$title" :eyebrow="$eyebrow" :lead="$lead" :size="$size" level="h1">
            @if ($slot->isNotEmpty())
                {{ $slot }}
            @endif
        </x-hvm.section-heading>
    </div>

    @isset($actions)
        <div class="flex flex-wrap gap-2 sm:shrink-0">
            {{ $actions }}
        </div>
    @endisset
</div>
