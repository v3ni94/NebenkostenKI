{{--
    ANLAGE: BELEGÜBERSICHT, OPTIONAL ZUSCHALTBAR (Abschnitt 14.1)

    DATENSCHUTZ: Die Übersicht wird ausschließlich aus strukturierten
    Extraktionsdaten erzeugt. Es werden KEINE Originaldateien eingebettet,
    KEINE Dateien verlinkt, KEINE Dateipfade und KEINE Originaldateinamen
    gedruckt. Die Originalbelege verbleiben beim Vermieter.

    ANWALTLICH FREIZUGEBEN: Der Hinweistext zum Einsichtsrecht ist vor Livegang
    zu prüfen und freizugeben.

    @var \App\Services\Pdf\View\TenantStatementView $view
    @var string $bodyFont
--}}
@include('pdf.partials.stil')

<div class="absenderzeile">{{ $view->sender->address->senderLine() }}</div>

<h1>Anlage: Belegübersicht</h1>

<table class="infoblock">
    <tr>
        <td class="bezeichnung">Objekt</td>
        <td>{{ $view->subject->propertyLabel }}</td>
    </tr>
    <tr>
        <td class="bezeichnung">Einheit</td>
        <td>{{ $view->subject->unitLabel ?? $view->result->unitLabel }}</td>
    </tr>
    <tr>
        <td class="bezeichnung">Abrechnungszeitraum</td>
        <td>{{ $view->result->billingPeriod->format() }}</td>
    </tr>
    <tr>
        <td class="bezeichnung">Datum</td>
        <td>{{ \App\Services\Pdf\Support\GermanDate::format($view->statementDate) }}</td>
    </tr>
</table>

<p>
    Die folgende Übersicht listet die Belege auf, die der Abrechnung zugrunde liegen. Angegeben sind
    die aus den Unterlagen ausgelesenen Inhaltsdaten. Die Übersicht enthält keine Kopien der Belege.
</p>

@if ($view->vouchers === [])
    <p>Für diese Abrechnung liegen keine strukturierten Belegangaben vor.</p>
@else
    <table class="kosten">
        <thead>
            <tr>
                <th>Nummer</th>
                <th>Kostenart</th>
                <th>Aussteller</th>
                <th>Belegdatum</th>
                <th class="betrag">Betrag EUR</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($view->vouchers as $beleg)
                <tr>
                    <td>{{ $beleg->number }}</td>
                    <td>
                        {{ $beleg->categoryLabel }}
                        @if ($beleg->documentTypeLabel)
                            <br><span class="rechenweg">{{ $beleg->documentTypeLabel }}</span>
                        @endif
                    </td>
                    <td>{{ $beleg->issuer ?? 'nicht ausgewiesen' }}</td>
                    <td>{{ \App\Services\Pdf\Support\GermanDate::formatOr($beleg->documentDate, 'nicht ausgewiesen') }}</td>
                    <td class="betrag">{{ $beleg->amount?->formatAmount() ?? 'nicht ausgewiesen' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<div class="hinweisblock">
    <p>
        Sie haben das Recht, die Originalbelege einzusehen. Die Originalbelege werden vom Vermieter
        selbst aufbewahrt und auf Anforderung zur Einsicht bereitgestellt. Bitte richten Sie eine
        Anfrage zur Belegeinsicht an den oben genannten Absender.
    </p>
    <p class="klein">
        Diese Übersicht wurde aus den ausgelesenen Inhaltsdaten der eingereichten Unterlagen
        erstellt. Kopien der Unterlagen werden über dieses Portal nicht gespeichert und nicht
        weitergegeben.
    </p>
</div>
