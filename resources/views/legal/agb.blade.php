{{--
    VOR LIVEGANG DURCH RECHTSANWALT PRÜFEN UND FREIGEBEN

    Platzhalterseite. Es werden keine Vertragsbedingungen formuliert.
    Angelegt ist ausschliesslich die Gliederung mit Platzhaltern in eckigen
    Klammern.
--}}
@extends('layouts.legal')

@section('meta_title', 'Allgemeine Geschäftsbedingungen')
@section('meta_description', 'Allgemeine Geschäftsbedingungen von smart-abrechnen.de. Die Seite ist eine strukturierte Platzhalterfassung und wird vor dem Livegang anwaltlich geprüft und freigegeben.')

@section('legal_title', 'Allgemeine Geschäftsbedingungen')
@section('legal_intro', 'Diese Seite enthält die Gliederung der Allgemeinen Geschäftsbedingungen. Die Textfassung wird vor dem Livegang anwaltlich erstellt und freigegeben.')

@section('legal_content')
    <section>
        <h2 class="text-xl font-semibold text-hvm-anthrazit">1. Geltungsbereich und Vertragspartner</h2>
        <p class="mt-3">[Anbieter, Nutzergruppen, Einbeziehung dieser Bedingungen, Vorrang individueller Vereinbarungen]</p>
    </section>

    <section>
        <h2 class="text-xl font-semibold text-hvm-anthrazit">2. Gegenstand der Leistung</h2>
        <p class="mt-3">[Beschreibung der Software als Werkzeug zur Erstellung von Betriebskostenabrechnungen]</p>
        <p class="mt-2">[Klarstellung, dass keine Rechtsberatung, Steuerberatung oder Verwaltertätigkeit erbracht wird]</p>
        <p class="mt-2">[Klarstellung, dass Absender und inhaltlich Verantwortlicher der Abrechnung der Vermieter bleibt]</p>
    </section>

    <section>
        <h2 class="text-xl font-semibold text-hvm-anthrazit">3. Registrierung und Nutzerkonto</h2>
        <p class="mt-3">[Voraussetzungen, Richtigkeit der Angaben, E-Mail-Verifizierung, Zugangsdaten und Sperrung]</p>
    </section>

    <section>
        <h2 class="text-xl font-semibold text-hvm-anthrazit">4. Vertragsschluss</h2>
        <p class="mt-3">[Zustandekommen des kostenlosen Kontos]</p>
        <p class="mt-2">[Zustandekommen des entgeltlichen Vertrags über die Erstellung der Final-PDFs]</p>
    </section>

    <section>
        <h2 class="text-xl font-semibold text-hvm-anthrazit">5. Mitwirkungspflichten des Nutzers</h2>
        <ul class="mt-3 list-disc space-y-2 pl-5">
            <li>[Vollständigkeit und Richtigkeit der hochgeladenen Unterlagen]</li>
            <li>[Berechtigung zur Verarbeitung der Daten Dritter, insbesondere der Mieter]</li>
            <li>[Prüfung und Bestätigung aller Werte, Schlüssel und Ergebnisse vor der Finalisierung]</li>
            <li>[eigene Aufbewahrung der Originalbelege und Gewährung der Belegeinsicht]</li>
            <li>[Versand der Abrechnung an die Mieter]</li>
        </ul>
    </section>

    <section>
        <h2 class="text-xl font-semibold text-hvm-anthrazit">6. Preise und Zahlung</h2>
        <p class="mt-3">[Preis je erzeugter Mieterabrechnung, Umsatzsteuer, Abrechnungseinheit und Mieterwechsel]</p>
        <p class="mt-2">[Zahlungsablauf über den Zahlungsdienstleister und Freigabe nach bestätigter Zahlung]</p>
        <p class="mt-2">[Rechnungsstellung durch die Betreiberin, Storno und Erstattung]</p>
        <p class="mt-2">[Regelung zu Korrekturen nach der Zahlung]</p>
    </section>

    <section>
        <h2 class="text-xl font-semibold text-hvm-anthrazit">7. Nutzungsrechte</h2>
        <p class="mt-3">[Umfang der Nutzungsrechte an der Software und an den erzeugten Dokumenten]</p>
        <p class="mt-2">[unzulässige Nutzung, insbesondere Umgehung des Wasserzeichens der Vorschau]</p>
    </section>

    <section>
        <h2 class="text-xl font-semibold text-hvm-anthrazit">8. Verfügbarkeit und Änderungen der Leistung</h2>
        <p class="mt-3">[Verfügbarkeit, Wartungsfenster, Weiterentwicklung und Änderungsvorbehalt]</p>
    </section>

    <section>
        <h2 class="text-xl font-semibold text-hvm-anthrazit">9. Gewährleistung und Haftung</h2>
        <p class="mt-3">[Haftungsregelung und Haftungsbegrenzung]</p>
        <p class="mt-2">[Abgrenzung zur inhaltlichen Verantwortung des Vermieters]</p>
        <p class="mt-2">[Hinweis zu automatisierter Auswertung und zur Prüfpflicht des Nutzers]</p>
    </section>

    <section>
        <h2 class="text-xl font-semibold text-hvm-anthrazit">10. Datenschutz</h2>
        <p class="mt-3">[Verweis auf die Datenschutzerklärung und auf das Löschkonzept für Uploads]</p>
        <p class="mt-2">[Regelung zur Rollenverteilung bei der Verarbeitung von Mieterdaten]</p>
    </section>

    <section>
        <h2 class="text-xl font-semibold text-hvm-anthrazit">11. Laufzeit, Kündigung und Kontolöschung</h2>
        <p class="mt-3">[Laufzeit des kostenlosen Kontos, Kündigung und Löschung]</p>
        <p class="mt-2">[Folgen der Löschung für erzeugte Dokumente und Rechnungen]</p>
    </section>

    <section>
        <h2 class="text-xl font-semibold text-hvm-anthrazit">12. Widerrufsrecht für Verbraucher</h2>
        <p class="mt-3">
            [Verweis auf die
            <a href="{{ route('legal.widerruf') }}" class="underline underline-offset-2">Widerrufsbelehrung</a>
            und auf die gesonderte Bestätigung im Bezahlvorgang]
        </p>
    </section>

    <section>
        <h2 class="text-xl font-semibold text-hvm-anthrazit">13. Änderungen dieser Bedingungen</h2>
        <p class="mt-3">[Verfahren und Ankündigungsfrist bei Änderungen]</p>
    </section>

    <section>
        <h2 class="text-xl font-semibold text-hvm-anthrazit">14. Schlussbestimmungen</h2>
        <p class="mt-3">[anwendbares Recht, Gerichtsstand, Streitbeilegung und salvatorische Klausel]</p>
    </section>
@endsection
