{{--
    RECHNUNG DER HAUSVERWALTUNG MÜLLER GMBH (Abschnitt 15.2 und 18)

    Dies ist das einzige PDF im Corporate Identity der Hausverwaltung Müller
    GmbH. Es trägt die HVM-Kennlinie am oberen Rand (etwa 3 mm) und die
    handelsrechtlichen Pflichtangaben.

    Das HVM-Logo wird nicht erfunden und nicht generiert. Es wird nur
    eingebunden, wenn public/ci/Logo_HVM.jpg vorliegt; andernfalls erscheint
    ein eindeutig benannter Textplatzhalter.

    Fehlende Steuer- und Bankdaten erscheinen als sichtbarer Platzhalter aus
    config('smartabrechnen.operator.placeholder_text') und werden niemals
    erfunden.

    @var \App\Services\Pdf\View\InvoiceView $view
    @var \App\Services\Pdf\Support\OperatorDetails $operator
    @var string|null $logoPath
    @var string $bodyFont
--}}
@include('pdf.partials.stil')

<style>
    table.kennlinie {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 4mm;
    }

    table.kennlinie td {
        height: {{ \App\Services\Pdf\Support\HvmCorporateIdentity::keylineHeightMm() }};
        line-height: {{ \App\Services\Pdf\Support\HvmCorporateIdentity::keylineHeightMm() }};
        font-size: 1pt;
        padding: 0;
        border: none;
    }

    .hvm-kopf {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 6mm;
    }

    .hvm-kopf td {
        vertical-align: top;
        font-size: 8.5pt;
        color: {{ \App\Services\Pdf\Support\HvmCorporateIdentity::ANTHRAZIT }};
    }

    .hvm-logoplatzhalter {
        font-size: 10pt;
        font-weight: bold;
        color: {{ \App\Services\Pdf\Support\HvmCorporateIdentity::ANTHRAZIT }};
    }

    table.rechnung {
        width: 100%;
        border-collapse: collapse;
        font-size: 9.2pt;
    }

    table.rechnung thead th {
        background-color: {{ \App\Services\Pdf\Support\HvmCorporateIdentity::ORANGE }};
        color: {{ \App\Services\Pdf\Support\HvmCorporateIdentity::TEXTSCHWARZ }};
        border-bottom: 0.3mm solid {{ \App\Services\Pdf\Support\HvmCorporateIdentity::MITTELGRAU }};
        padding: 1.4mm;
        text-align: left;
    }

    table.rechnung td {
        border-bottom: 0.1mm solid {{ \App\Services\Pdf\Support\HvmCorporateIdentity::HELLGRAU }};
        padding: 1.4mm;
        vertical-align: top;
    }

    table.rechnung td.betrag,
    table.rechnung th.betrag {
        text-align: right;
        white-space: nowrap;
    }

    .pflichtangaben {
        margin-top: 8mm;
        border-top: 0.2mm solid {{ \App\Services\Pdf\Support\HvmCorporateIdentity::MITTELGRAU }};
        padding-top: 2mm;
        font-size: 7.5pt;
        color: {{ \App\Services\Pdf\Support\HvmCorporateIdentity::ANTHRAZIT }};
    }

    .platzhalter {
        font-weight: bold;
    }
</style>

<table class="kennlinie">
    <tr>
        @foreach (\App\Services\Pdf\Support\HvmCorporateIdentity::keylineSegments() as $segment)
            <td width="{{ $segment['width'] }}" bgcolor="{{ $segment['color'] }}">&nbsp;</td>
        @endforeach
    </tr>
</table>

<table class="hvm-kopf">
    <tr>
        <td width="60%">
            @if ($logoPath)
                <img src="{{ $logoPath }}" height="40" alt="Hausverwaltung Müller GmbH">
            @else
                <span class="hvm-logoplatzhalter">{{ \App\Services\Pdf\Support\HvmCorporateIdentity::LOGO_PLACEHOLDER }}</span>
            @endif
        </td>
        <td width="40%" align="right">
            {{ $operator->legalName() }}<br>
            {{ $operator->addressLine() }}<br>
            {{ $operator->cityLine() }}<br>
            {{ $operator->website() }}
        </td>
    </tr>
</table>

<div class="absenderzeile">{{ $operator->legalName() }}, {{ $operator->addressLine() }}, {{ $operator->cityLine() }}</div>

@include('pdf.partials.anschrift', ['adresse' => $view->customer])

<table class="infoblock">
    <tr>
        <td class="bezeichnung">Rechnungsnummer</td>
        <td>{{ $view->number }}</td>
    </tr>
    <tr>
        <td class="bezeichnung">Rechnungsdatum</td>
        <td>{{ \App\Services\Pdf\Support\GermanDate::format($view->issuedOn) }}</td>
    </tr>
    <tr>
        <td class="bezeichnung">Leistungsdatum</td>
        <td>{{ \App\Services\Pdf\Support\GermanDate::format($view->serviceDate) }}</td>
    </tr>
    @if ($view->customerVatId)
        <tr>
            <td class="bezeichnung">Umsatzsteuer-Identifikationsnummer des Kunden</td>
            <td>{{ $view->customerVatId }}</td>
        </tr>
    @endif
    @if ($view->cancelsInvoiceNumber)
        <tr>
            <td class="bezeichnung">Storniert Rechnung</td>
            <td>{{ $view->cancelsInvoiceNumber }}</td>
        </tr>
    @endif
</table>

<h1>{{ $view->subjectLine() }}</h1>

<table class="rechnung">
    <thead>
        <tr>
            <th>Position</th>
            <th class="betrag">Anzahl</th>
            <th class="betrag">Einzelpreis netto EUR</th>
            <th class="betrag">Netto EUR</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($view->lines as $position)
            <tr>
                <td>
                    {{ $position->description }}
                    <br><span class="klein">Abrechnungseinheit: {{ $position->unitLabel }}</span>
                </td>
                <td class="betrag">{{ $position->quantity }}</td>
                <td class="betrag">{{ $position->unitPriceNet->formatAmount() }}</td>
                <td class="betrag">{{ $position->totalNet->formatAmount() }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="ergebnis">
    <tr>
        <td>Nettobetrag</td>
        <td class="betrag">{{ $view->netTotal->format() }}</td>
    </tr>
    <tr>
        <td>Umsatzsteuer {{ \App\Domain\Support\GermanNumberFormatter::decimal($view->taxRatePercent, 2) }} Prozent</td>
        <td class="betrag">{{ $view->taxTotal->format() }}</td>
    </tr>
    <tr class="hervorgehoben">
        <td>Bruttobetrag</td>
        <td class="betrag">{{ $view->grossTotal->format() }}</td>
    </tr>
</table>

<table class="infoblock">
    <tr>
        <td class="bezeichnung">Zahlungsart</td>
        <td>{{ $view->paymentMethod }}</td>
    </tr>
    <tr>
        <td class="bezeichnung">Stripe-Referenz</td>
        <td>{{ $view->paymentReference ?? 'nicht ausgewiesen' }}</td>
    </tr>
</table>

@if ($view->isCancellation())
    <p>
        Diese Stornorechnung hebt die genannte Rechnung auf. Eine etwaige Erstattung erfolgt gesondert.
    </p>
@else
    <p>
        Der Betrag wurde über die angegebene Zahlungsart bereits vollständig entrichtet. Eine
        gesonderte Überweisung ist nicht erforderlich.
    </p>
@endif

<div class="pflichtangaben">
    {{ $operator->legalName() }} | {{ $operator->addressLine() }} | {{ $operator->cityLine() }}<br>
    {{ $operator->registerLine() }} | {{ $operator->managingDirectorLine() }}<br>
    Steuernummer: <span class="platzhalter">{{ $operator->taxId() }}</span> |
    Umsatzsteuer-Identifikationsnummer: <span class="platzhalter">{{ $operator->vatId() }}</span><br>
    IBAN: <span class="platzhalter">{{ $operator->iban() }}</span> |
    BIC: <span class="platzhalter">{{ $operator->bic() }}</span><br>
    {{ $operator->website() }}
</div>
