{{--
    ANLAGE: ERLÄUTERUNG DER VERTEILERSCHLÜSSEL (Abschnitt 14.1)

    Neutrale Anlage zur Mieterabrechnung. Kein Logo, keine Kennlinie, keine
    CI-Farben der Hausverwaltung. Die Erläuterungen stammen unverändert aus den
    Kostenzeilen des Berechnungsergebnisses.

    @var \App\Services\Pdf\View\TenantStatementView $view
    @var string $bodyFont
--}}
@include('pdf.partials.stil')

<div class="absenderzeile">{{ $view->sender->address->senderLine() }}</div>

<h1>Anlage: Erläuterung der Verteilerschlüssel</h1>

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
    Die folgenden Verteilerschlüssel wurden in Ihrer Abrechnung angewendet. Der Zähler ist Ihr
    individueller Anteil, der Nenner die Gesamtsumme des Objekts. Zusätzlich wird der Zeitanteil
    Ihres Nutzungszeitraums berücksichtigt.
</p>

<table class="kosten">
    <thead>
        <tr>
            <th>Verteilerschlüssel</th>
            <th>Erläuterung</th>
            <th>Angewendet auf</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($view->allocationKeyExplanations() as $schluessel)
            <tr>
                <td>{{ $schluessel['label'] }}</td>
                <td>{{ $schluessel['explanation'] }}</td>
                <td>{{ implode(', ', $schluessel['categories']) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<h2>Zeitanteilige Berechnung</h2>
<table class="kosten">
    <thead>
        <tr>
            <th>Kostenart</th>
            <th>Zeitanteil</th>
            <th>Ihr Anteil</th>
            <th class="betrag">Betrag EUR</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($view->result->lines as $zeile)
            <tr>
                <td>{{ $zeile->categoryLabel }}</td>
                <td>{{ $zeile->timeFactor->explanation() }}</td>
                <td>{{ $zeile->numerator }} von {{ $zeile->denominator }}</td>
                <td class="betrag">{{ $zeile->share->formatAmount() }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<p class="klein">
    Der Nutzungszeitraum umfasst {{ $view->result->usageDays() }} von
    {{ $view->result->billingPeriod->days() }} Tagen des Abrechnungszeitraums. Kalendertage werden
    taggenau gezählt, Start- und Endtag zählen jeweils mit.
</p>
