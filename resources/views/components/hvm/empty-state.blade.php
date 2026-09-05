{{--
    Leerzustand des HVM-Designsystems. Auf .hvm-dark-Flaechen automatisch
    dunkel.

    Gestrichelte Karte in Canvas mit Icon, Titel, erklaerendem Satz und
    optionaler Handlung. Der Slot enthaelt den Text, der Slot "action" die
    Schaltflaeche(n).

    Props:
      title  Kurztitel (optional)
      icon   Name eines x-hvm.icon, Standard "document"
      level  Ueberschriftenebene, Standard h3
--}}
@props([
    'title' => null,
    'icon' => 'document',
    'level' => 'h3',
])

<div {{ $attributes->class('rounded-2xl border border-dashed border-hvm-hellgrau bg-hvm-canvas px-6 py-10 text-center sm:px-10 sm:py-12 [.hvm-dark_&]:border-hvm-graphit-soft [.hvm-dark_&]:bg-hvm-graphit-soft/40') }}>
    <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full border border-hvm-linie bg-white text-hvm-text-sekundaer [.hvm-dark_&]:border-hvm-graphit-soft [.hvm-dark_&]:bg-hvm-graphit [.hvm-dark_&]:text-hvm-orange" aria-hidden="true">
        <x-hvm.icon :name="$icon" class="h-6 w-6" />
    </span>

    @if ($title !== null)
        <{{ $level }} class="mt-4 text-lg font-semibold tracking-tight text-hvm-textschwarz [.hvm-dark_&]:text-white">{{ $title }}</{{ $level }}>
    @endif

    <div class="mx-auto mt-2 max-w-md text-base leading-relaxed text-hvm-text-sekundaer [.hvm-dark_&]:text-hvm-hellgrau">
        {{ $slot }}
    </div>

    @isset($action)
        <div class="mt-6 flex flex-wrap justify-center gap-3">
            {{ $action }}
        </div>
    @endisset
</div>
