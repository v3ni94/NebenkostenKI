{{--
    Neutrale Quellenangabe einer Kostenposition.

    Zulässig ist ausschließlich die neutrale Quellenbezeichnung, die Seite und
    der kurze gespeicherte Fundstellenausschnitt. Der Originaldateiname, der
    vollständige Text und eine Seitenansicht sind unzulässig.
--}}
<div class="mt-3 rounded-md bg-hvm-umrissgrau p-3 text-sm">
    <p>
        Quelle: {{ $position->sourceLabel }}@if ($position->sourcePage !== null), Seite {{ $position->sourcePage }}@endif
    </p>

    @if ($position->sourceExcerpt !== null)
        <p class="mt-1">Fundstelle: „{{ $position->sourceExcerpt }}“</p>
    @endif

    @if ($position->manuallyEntered)
        <p class="mt-1">Diese Position haben Sie selbst erfasst.</p>
    @else
        <p class="mt-1">
            Eine Seitenansicht ist nicht möglich, weil die Originaldatei nach der Auswertung gelöscht wurde.
        </p>
    @endif
</div>
