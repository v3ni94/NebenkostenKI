{{--
    VOR LIVEGANG DURCH RECHTSANWALT PRÜFEN UND FREIGEBEN

    Platzhalterseite. Es werden keine datenschutzrechtlichen Inhalte
    formuliert. Angelegt ist ausschliesslich die Gliederung mit Platzhaltern
    in eckigen Klammern.
--}}
@extends('layouts.legal')

@section('meta_title', 'Datenschutzerklärung')
@section('meta_description', 'Datenschutzerklärung von smart-abrechnen.de. Die Seite ist eine strukturierte Platzhalterfassung und wird vor dem Livegang anwaltlich geprüft und freigegeben.')

@section('legal_title', 'Datenschutzerklärung')
@section('legal_intro', 'Diese Seite enthält die Gliederung der Datenschutzerklärung. Die Textfassung wird vor dem Livegang anwaltlich erstellt und freigegeben.')

@section('legal_content')
    <section>
        <x-hvm.alert variant="info" title="Verständliche Erläuterung getrennt verfügbar">
            Die Funktionsweise der Uploadverarbeitung und der automatischen Löschung ist unter
            <a href="{{ route('site.datenschutz-konzept') }}" class="underline underline-offset-2">Datenschutz und Löschung</a>
            allgemein verständlich beschrieben. Diese Erläuterung ist keine Datenschutzerklärung und ersetzt sie nicht.
        </x-hvm.alert>
    </section>

    <section>
        <h2 class="text-xl font-semibold text-hvm-anthrazit">1. Verantwortlicher und Datenschutzbeauftragter</h2>
        <p class="mt-3">[Verantwortlicher im Sinne der Datenschutz-Grundverordnung, Angaben siehe Impressum]</p>
        <p class="mt-2">[Kontaktdaten für Datenschutzanfragen]</p>
        <p class="mt-2">[Angabe, ob ein Datenschutzbeauftragter benannt ist]</p>
    </section>

    <section>
        <h2 class="text-xl font-semibold text-hvm-anthrazit">2. Begriffe und Grundsätze</h2>
        <p class="mt-3">[Begriffsbestimmungen und Grundsätze der Verarbeitung]</p>
    </section>

    <section>
        <h2 class="text-xl font-semibold text-hvm-anthrazit">3. Verarbeitete Datenkategorien</h2>
        <ul class="mt-3 list-disc space-y-2 pl-5">
            <li>[Konto- und Kontaktdaten]</li>
            <li>[Vertrags- und Abrechnungsdaten]</li>
            <li>[Objekt-, Einheiten- und Mietverhältnisdaten einschließlich Daten Dritter]</li>
            <li>[strukturierte Extraktionsdaten aus hochgeladenen Unterlagen]</li>
            <li>[temporär verarbeitete Originaldateien]</li>
            <li>[Nutzungs-, Protokoll- und Sicherheitsdaten]</li>
            <li>[Zahlungsdaten]</li>
            <li>[Kommunikationsdaten]</li>
        </ul>
    </section>

    <section>
        <h2 class="text-xl font-semibold text-hvm-anthrazit">4. Zwecke und Rechtsgrundlagen</h2>
        <p class="mt-3">[Zwecke der Verarbeitung je Datenkategorie]</p>
        <p class="mt-2">[Rechtsgrundlagen je Verarbeitungsvorgang]</p>
        <p class="mt-2">[Hinweis auf die Verarbeitung von Daten der Mieter im Auftrag des Vermieters und die Rollenverteilung]</p>
    </section>

    <section>
        <h2 class="text-xl font-semibold text-hvm-anthrazit">5. Uploads, temporäre Verarbeitung und Löschung</h2>
        <p class="mt-3">[Beschreibung des temporären verschlüsselten Arbeitsbereichs]</p>
        <p class="mt-2">[Löschung nach Auswertung, Löschung bei Fehler und maximale Aufbewahrungsdauer]</p>
        <p class="mt-2">[Ausschluss der temporären Daten aus Backups]</p>
        <p class="mt-2">[Umfang der dauerhaft gespeicherten strukturierten Extraktionsdaten]</p>
        <p class="mt-2">[Hinweis auf die Aufbewahrungspflicht des Nutzers für eigene Originalbelege]</p>
    </section>

    <section>
        <h2 class="text-xl font-semibold text-hvm-anthrazit">6. Einsatz von KI-Diensten</h2>
        <p class="mt-3">[Benennung des eingesetzten Dienstes und des Verarbeitungszwecks]</p>
        <p class="mt-2">[Umfang der übermittelten Inhalte]</p>
        <p class="mt-2">[Angaben zu Speicherdauer, Löschung und Datenschutzentscheidung des Anbieters]</p>
        <p class="mt-2">[Angaben zu Drittlandübermittlungen und Garantien]</p>
    </section>

    <section>
        <h2 class="text-xl font-semibold text-hvm-anthrazit">7. Empfänger und Auftragsverarbeiter</h2>
        <ul class="mt-3 list-disc space-y-2 pl-5">
            <li>[Hosting und Infrastruktur]</li>
            <li>[E-Mail-Versand]</li>
            <li>[Zahlungsdienstleister]</li>
            <li>[KI-Dienst für die Dokumentauswertung]</li>
            <li>[weitere Dienstleister]</li>
        </ul>
    </section>

    <section>
        <h2 class="text-xl font-semibold text-hvm-anthrazit">8. Speicherdauer</h2>
        <p class="mt-3">[Speicher- und Löschfristen je Datenkategorie]</p>
        <p class="mt-2">[gesetzliche Aufbewahrungspflichten für Rechnungen]</p>
    </section>

    <section>
        <h2 class="text-xl font-semibold text-hvm-anthrazit">9. Cookies und technisch notwendige Speicherung</h2>
        <p class="mt-3">[Auflistung der technisch notwendigen Cookies und ihrer Laufzeit]</p>
        <p class="mt-2">[Hinweis, dass keine Analyse- und Marketingtracker eingesetzt werden]</p>
    </section>

    <section>
        <h2 class="text-xl font-semibold text-hvm-anthrazit">10. Ihre Rechte</h2>
        <p class="mt-3">[Auskunft, Berichtigung, Löschung, Einschränkung, Datenübertragbarkeit, Widerspruch]</p>
        <p class="mt-2">[Widerruf von Einwilligungen]</p>
        <p class="mt-2">[Beschwerderecht bei einer Aufsichtsbehörde und Angabe der zuständigen Behörde]</p>
    </section>

    <section>
        <h2 class="text-xl font-semibold text-hvm-anthrazit">11. Datensicherheit</h2>
        <p class="mt-3">[technische und organisatorische Maßnahmen in zusammengefasster Form]</p>
    </section>

    <section>
        <h2 class="text-xl font-semibold text-hvm-anthrazit">12. Änderungen dieser Erklärung</h2>
        <p class="mt-3">[Verfahren bei Aktualisierungen und Angabe des Stands]</p>
    </section>
@endsection
