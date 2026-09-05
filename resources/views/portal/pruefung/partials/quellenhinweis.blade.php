{{--
    Neutrale Quellenangabe einer Kostenposition.

    Zulässig ist ausschließlich die neutrale Quellenbezeichnung, die Seite und
    der kurze gespeicherte Fundstellenausschnitt. Der Originaldateiname, der
    vollständige Text und eine Seitenansicht sind unzulässig.
--}}
<div class="mt-4 flex gap-3 rounded-2xl border border-hvm-linie bg-white p-4 text-sm leading-relaxed">
    <span class="mt-0.5 shrink-0 text-hvm-text-sekundaer" aria-hidden="true">
        <x-hvm.icon name="document" class="h-5 w-5" />
    </span>

    <div class="min-w-0">
        <p class="text-hvm-textschwarz">
            Quelle: {{ $position->sourceLabel }}@if ($position->sourcePage !== null), Seite {{ $position->sourcePage }}@endif
        </p>

        @if ($position->sourceExcerpt !== null)
            <p class="mt-1 text-hvm-textschwarz">Fundstelle: „{{ $position->sourceExcerpt }}“</p>
        @endif

        @if ($position->manuallyEntered)
            <p class="mt-1 text-hvm-text-sekundaer">Diese Position haben Sie selbst erfasst.</p>
        @else
            <p class="mt-1 text-hvm-text-sekundaer">
                Eine Seitenansicht ist nicht möglich, weil die Originaldatei nach der Auswertung gelöscht wurde.
            </p>
        @endif
    </div>
</div>
