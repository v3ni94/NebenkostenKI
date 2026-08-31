{{--
    Statusanzeige in der Sprache der Oberflaeche.

    Es erscheint ausschliesslich eine der vier Kategorien Erledigt, Bitte
    prüfen, Fehlt noch und Blockiert die Abrechnung, dazu ein Satz in
    Alltagssprache. Ein technischer Statuscode oder ein Providername wird
    niemals ausgegeben (Masterprompt 9).

    Der Status wird nie allein ueber Farbe kommuniziert, die Kategorie steht
    immer als Text im Etikett.

    Erwartet:
      $status  App\Application\BillingRun\PortalStatus
--}}
<div class="space-y-2">
    <x-hvm.badge :variant="$status->variante()">{{ $status->kategorie }}</x-hvm.badge>

    <p class="text-sm text-hvm-textschwarz">{{ $status->hinweis }}</p>

    @if ($status->details !== [])
        <ul class="list-disc space-y-1 pl-5 text-sm text-hvm-textschwarz">
            @foreach ($status->details as $detail)
                <li>{{ $detail }}</li>
            @endforeach
        </ul>
    @endif
</div>
