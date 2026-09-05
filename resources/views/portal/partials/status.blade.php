{{--
    Statusanzeige in der Sprache der Oberflaeche (Konzept A).

    Es erscheint ausschliesslich eine der vier Kategorien Erledigt, Bitte
    prüfen, Fehlt noch und Blockiert die Abrechnung, dazu ein Satz in
    Alltagssprache. Ein technischer Statuscode oder ein Providername wird
    niemals ausgegeben (Masterprompt 9).

    Der Status wird nie allein ueber Farbe kommuniziert, die Kategorie steht
    immer als Text im Etikett, das Etikett traegt zusaetzlich einen
    Statuspunkt.

    Erwartet:
      $status  App\Application\BillingRun\PortalStatus
--}}
<div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:gap-4">
    <x-hvm.badge :variant="$status->variante()" class="shrink-0 self-start">{{ $status->kategorie }}</x-hvm.badge>

    <div class="min-w-0 text-sm leading-relaxed text-hvm-text-sekundaer">
        <p>{{ $status->hinweis }}</p>

        @if ($status->details !== [])
            <ul class="mt-2 space-y-1">
                @foreach ($status->details as $detail)
                    <li class="flex gap-2">
                        <span class="mt-2 h-1 w-1 shrink-0 rounded-full bg-hvm-mittelgrau" aria-hidden="true"></span>
                        <span>{{ $detail }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
