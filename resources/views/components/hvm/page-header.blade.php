{{--
    Seitenkopf des Portals (Konzept A).

    Titel links, Handlungen rechts, optionale Einordnung und Einleitung.
    Ersetzt das Muster "section-heading plus Button in einer Flexbox".

    Props:
      title    Seitentitel (h1)
      eyebrow  kurze Einordnung
      lead     einleitender Satz

    Slot "actions": Schaltflaechen rechts.
--}}
@props([
    'title',
    'eyebrow' => null,
    'lead' => null,
])

<div {{ $attributes->class('flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between') }}>
    <x-hvm.section-heading :title="$title" :eyebrow="$eyebrow" :lead="$lead" level="h1" />

    @isset($actions)
        <div class="flex flex-wrap gap-2 sm:shrink-0">
            {{ $actions }}
        </div>
    @endisset
</div>
