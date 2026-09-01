{{--
    Gemeinsames PDF-Stylesheet.

    VERBINDLICH (ADR-005, Abschnitt 3.6): konservatives, tabellenorientiertes
    CSS. Kein Flexbox, kein Grid, keine CSS-Variablen. Fließtext 10 bis 11 pt
    in Helvetica beziehungsweise Arial. Die Farbwerte sind neutrale Grautöne;
    die CI-Farben der Hausverwaltung stehen ausschließlich in der Vorlage der
    HVM-Rechnung.
--}}
<style>
    body {
        font-family: Helvetica, Arial, sans-serif;
        font-size: {{ $bodyFont }};
        color: #1a1a1a;
        line-height: 1.35;
    }

    p {
        margin: 0 0 2.5mm 0;
    }

    h1 {
        font-size: 12.5pt;
        margin: 0 0 3mm 0;
    }

    h2 {
        font-size: 11pt;
        margin: 5mm 0 2mm 0;
    }

    h3 {
        font-size: 10.5pt;
        margin: 4mm 0 1.5mm 0;
    }

    .absenderzeile {
        font-size: 7.5pt;
        color: #444444;
        border-bottom: 0.2mm solid #cccccc;
        padding-bottom: 1mm;
        margin-bottom: 2mm;
    }

    .anschriftfeld {
        margin: 4mm 0 8mm 0;
    }

    .infoblock {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 5mm;
        font-size: 9.5pt;
    }

    .infoblock td {
        padding: 0.6mm 0;
        vertical-align: top;
    }

    .infoblock td.bezeichnung {
        width: 42%;
        color: #444444;
    }

    table.kosten {
        width: 100%;
        border-collapse: collapse;
        font-size: 8.8pt;
    }

    table.kosten thead th {
        background-color: #eeeeee;
        border-bottom: 0.3mm solid #999999;
        padding: 1.2mm 1.2mm;
        text-align: left;
        font-weight: bold;
    }

    table.kosten td {
        border-bottom: 0.1mm solid #dddddd;
        padding: 1.2mm 1.2mm;
        vertical-align: top;
    }

    table.kosten td.betrag,
    table.kosten th.betrag {
        text-align: right;
        white-space: nowrap;
    }

    table.kosten tr.summe td {
        border-top: 0.3mm solid #999999;
        border-bottom: none;
        font-weight: bold;
    }

    table.kosten tr.zwischensumme td {
        border-top: 0.2mm solid #999999;
        font-weight: bold;
    }

    .rechenweg {
        font-size: 7.8pt;
        color: #444444;
    }

    table.ergebnis {
        width: 100%;
        border-collapse: collapse;
        margin-top: 4mm;
        font-size: 9.5pt;
    }

    table.ergebnis td {
        padding: 1.2mm;
        border-bottom: 0.1mm solid #dddddd;
    }

    table.ergebnis td.betrag {
        text-align: right;
        white-space: nowrap;
        width: 32mm;
    }

    table.ergebnis tr.hervorgehoben td {
        font-weight: bold;
        font-size: 11pt;
        border-top: 0.4mm solid #333333;
        border-bottom: 0.4mm solid #333333;
        background-color: #f2f2f2;
    }

    .hinweisblock {
        margin-top: 5mm;
        font-size: 8.5pt;
        color: #1a1a1a;
    }

    .kennzeichnung {
        border: 0.2mm solid #999999;
        background-color: #f5f5f5;
        padding: 2mm;
        margin: 3mm 0;
        font-size: 8.8pt;
    }

    ul {
        margin: 0 0 2mm 4mm;
        padding: 0;
    }

    .klein {
        font-size: 8pt;
        color: #444444;
    }

    .seitenumbruch {
        page-break-before: always;
    }
</style>
