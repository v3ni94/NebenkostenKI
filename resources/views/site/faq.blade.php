@extends('layouts.site')

@section('meta_title', 'Häufige Fragen')
@section('meta_description', 'Antworten zu Löschung der Uploads, fehlenden Angaben, Mieterwechsel, Leerstand, Heizkosten, Umlagefähigkeit, Preis, Zahlung, Rechnung, Folgejahren und Kontolöschung.')

@php
    $bruttoJeAbrechnungCent = (int) config('smartabrechnen.pricing.per_statement_gross_cent');
    $preisBrutto = number_format($bruttoJeAbrechnungCent / 100, 2, ',', '.').' EUR';
    $ttlMinuten = (int) config('smartabrechnen.retention.temp_upload_ttl_minutes');
    $operator = config('smartabrechnen.operator');

    // Erinnerungstermine liegen als MM-TT in der Konfiguration und werden als TT.MM. angezeigt.
    $termin = static function (mixed $wert): string {
        if (! is_string($wert) || preg_match('/^(\d{2})-(\d{2})$/', $wert, $teile) !== 1) {
            return '';
        }

        return $teile[2].'.'.$teile[1].'.';
    };

    $terminQ1 = $termin(config('smartabrechnen.reminders.q1'));
    $terminQ2 = $termin(config('smartabrechnen.reminders.q2'));
    $terminQ3 = $termin(config('smartabrechnen.reminders.q3'));
    $terminDezember = $termin(config('smartabrechnen.reminders.december'));
@endphp

@section('content')
    <section class="border-b border-hvm-umrissgrau bg-hvm-umrissgrau">
        <div class="mx-auto max-w-4xl px-4 py-12 sm:px-6 sm:py-16">
            <x-hvm.badge variant="akzent">Häufige Fragen</x-hvm.badge>
            <h1 class="mt-5 text-3xl font-bold text-hvm-anthrazit sm:text-4xl">
                Antworten auf die Fragen, die am häufigsten gestellt werden
            </h1>
            <p class="mt-5 text-lg leading-relaxed text-hvm-textschwarz">
                Die Antworten sind fachliche Erläuterungen zur Funktionsweise des Portals. Eine Rechtsberatung im
                Einzelfall ist damit nicht verbunden.
            </p>
        </div>
    </section>

    <div class="mx-auto max-w-4xl px-4 py-12 sm:px-6 sm:py-16">
        <section>
            <h2 class="text-2xl font-bold text-hvm-anthrazit">Unterlagen und Datenschutz</h2>

            <div class="mt-6 border-t border-hvm-hellgrau">
                <x-hvm.faq-item question="Warum werden meine Dateien gelöscht?" :open="true">
                    <p>
                        Für die Abrechnung sind nur die Werte aus Ihren Unterlagen erforderlich, nicht die Unterlagen
                        selbst. Deshalb werden die Originaldateien nur für die Auswertung kurzfristig verarbeitet und
                        anschließend automatisch gelöscht, spätestens nach {{ $ttlMinuten }} Minuten. Das hält die
                        Datenmenge klein und begrenzt das Risiko. Dauerhaft bleiben die ausgelesenen Inhaltsdaten mit
                        Angabe von Quelle und Seite.
                    </p>
                    <p class="mt-2">
                        Bitte bewahren Sie Ihre Originalbelege selbst auf. Weitere Angaben finden Sie unter
                        <a href="{{ route('site.datenschutz-konzept') }}" class="underline underline-offset-2">Datenschutz und Löschung</a>.
                    </p>
                </x-hvm.faq-item>

                <x-hvm.faq-item question="Welche Unterlagen brauche ich?">
                    <p>
                        Für die Schnellabrechnung einer Eigentumswohnung sind die Hausgeldabrechnung und der
                        Grundsteuerbescheid die wichtigsten Unterlagen, ergänzt um eine separate
                        Heizkostenabrechnung. Für die vollständige Objektabrechnung kommen alle Rechnungen und
                        Gebührenbescheide des Objekts hinzu. Sie können alles gleichzeitig und ungeordnet hochladen.
                    </p>
                    <p class="mt-2">
                        Mietverträge, Vorjahresabrechnungen, Zahlungsübersichten, Mieterlisten und Zählerlisten
                        können Sie ebenfalls hochladen. Sie werden abgelegt und Ihnen zur Sichtprüfung angezeigt.
                        Die Werte daraus, etwa Vorauszahlungen, Umlagevereinbarungen, Mietzeiten und Zählerstände,
                        erfassen Sie selbst in den geführten Schritten; sie werden derzeit nicht automatisch
                        übernommen.
                    </p>
                </x-hvm.faq-item>

                <x-hvm.faq-item question="Kann ich Dateien auch als Foto hochladen?">
                    <p>
                        Ja. Neben PDF-Dateien werden gängige Bildformate und CSV-Tabellen angenommen; Excel-Dateien
                        (XLSX) speichern Sie bitte als CSV oder PDF. Achten Sie bei Fotos auf gute Lesbarkeit,
                        vollständige Seiten und ausreichend Licht. Unlesbare Dateien werden nicht geraten, sondern
                        zurückgemeldet.
                    </p>
                </x-hvm.faq-item>

                <x-hvm.faq-item question="Wie kann ich mein Konto löschen?">
                    <p>
                        Sie können die Löschung Ihres Kontos in den Kontoeinstellungen veranlassen. Vorher können Sie
                        Ihre Daten als Archivdatei exportieren. Rechnungen, die aus steuer- und handelsrechtlichen
                        Gründen aufzubewahren sind, werden vom gelöschten Konto entkoppelt und nur im erforderlichen
                        Umfang weiter aufbewahrt. Ihre Originalunterlagen sind zu diesem Zeitpunkt ohnehin längst
                        gelöscht.
                    </p>
                </x-hvm.faq-item>
            </div>
        </section>

        <section class="mt-14">
            <h2 class="text-2xl font-bold text-hvm-anthrazit">Berechnung und Sonderfälle</h2>

            <div class="mt-6 border-t border-hvm-hellgrau">
                <x-hvm.faq-item question="Was passiert, wenn eine Angabe fehlt?">
                    <p>
                        Fehlende Werte werden nicht geschätzt. Das Feld bleibt leer und Sie erhalten eine konkrete
                        Prüfaufgabe, die benennt, welche Angabe fehlt und warum sie benötigt wird. Solange eine für die
                        Abrechnung zwingende Angabe fehlt, wird die Abrechnung nicht abgeschlossen.
                    </p>
                    <p class="mt-2">
                        Liegt zum Beispiel nur der monatliche Hausgeldbetrag oder nur die Abrechnungsspitze ohne
                        Kostenaufschlüsselung vor, wird keine scheinbar vollständige Abrechnung erzeugt. Das Portal
                        fordert dann die Einzelabrechnung an.
                    </p>
                </x-hvm.faq-item>

                <x-hvm.faq-item question="Wie werden Mieterwechsel und Leerstand behandelt?">
                    <p>
                        Zeitanteile werden taggenau nach Kalendertagen berechnet, einschließlich Schaltjahr. Bei einem
                        Mieterwechsel entstehen zwei Nutzungszeiträume und damit zwei Mieterabrechnungen ohne
                        Überschneidung.
                    </p>
                    <p class="mt-2">
                        Kosten für Zeiträume ohne Mietverhältnis bleiben beim Eigentümer und erscheinen in der
                        Eigentümerübersicht als Leerstandsanteil. Verbrauchswerte werden bei einem Nutzerwechsel nur
                        anhand einer Zwischenablesung geteilt. Fehlt diese, erfolgt keine stille Schätzung. Eine von
                        Ihnen ausdrücklich bestätigte Ersatzverteilung wird in der Abrechnung gekennzeichnet. Der
                        Schlüssel Personentage setzt durchgehend vermietete Einheiten voraus; bei Leerstand wählen
                        Sie für die betroffene Kostenart einen anderen Schlüssel, weil Personen für den Leerstand
                        nicht geschätzt werden.
                    </p>
                </x-hvm.faq-item>

                <x-hvm.faq-item question="Was ist mit den Heizkosten?">
                    <p>
                        Liegt eine externe Heizkostenabrechnung vor, werden deren Einzelbeträge je Einheit übernommen
                        und gegen die Gesamtsumme geprüft. Eine Summenposition aus der Hausgeldabrechnung wird dann
                        nicht zusätzlich angesetzt, damit keine Position doppelt in die Abrechnung gelangt.
                    </p>
                    <p class="mt-2">
                        Eine vollständige eigene Heizkostenberechnung wird nur freigegeben, wenn alle dafür nötigen
                        Angaben vorliegen, etwa Grundkosten, Verbrauchskosten, Verbrauchswerte, Betriebsstrom,
                        Warmwasseranteil und die Angaben zur Kohlendioxidkostenaufteilung. Bezieht der Mieter die
                        Energie direkt, werden keine Heizkosten als Vermieterkosten angesetzt. Grundlage sind die
                        mietvertragliche Vereinbarung und die Heizkostenverordnung.
                    </p>
                </x-hvm.faq-item>

                <x-hvm.faq-item question="Warum sind Verwaltungs- und Reparaturkosten nicht umlagefähig?">
                    <p>
                        Umlagefähig sind grundsätzlich laufende Betriebskosten, die im Mietvertrag vereinbart sind.
                        Verwaltungskosten, Instandhaltung, Instandsetzung, Reparaturen, Bank- und Finanzierungskosten,
                        Rechtskosten und die Zuführung zur Erhaltungsrücklage zählen üblicherweise nicht dazu. Das
                        Portal schließt solche Positionen daher voreingestellt aus und weist sie getrennt aus.
                    </p>
                    <p class="mt-2">
                        Sie können eine Position abweichend behandeln. Das erfordert eine Begründung und wird deutlich
                        als Warnung gekennzeichnet. Eine solche Entscheidung ist keine rechtliche Freigabe. Maßstab
                        sind Ihr Mietvertrag und die Betriebskostenverordnung. Bei Zweifeln lassen Sie die Position
                        anwaltlich prüfen.
                    </p>
                </x-hvm.faq-item>

                <x-hvm.faq-item question="Ist eine Kennzeichnung in der Hausgeldabrechnung als umlagefähig verbindlich?">
                    <p>
                        Nein. Eine solche Kennzeichnung der Verwaltung ist ein Vorschlag und wird mit Ihrem
                        Mietvertrag, der Kostenart und den vorhandenen Einzelbelegen abgeglichen. Bleibt eine Position
                        unklar, erhalten Sie eine Prüfaufgabe.
                    </p>
                </x-hvm.faq-item>

                <x-hvm.faq-item question="Wie wird die Grundsteuer behandelt?">
                    <p>
                        Jahresbetrag und Zeitraum werden aus dem Grundsteuerbescheid übernommen. Zuvor prüft das
                        Portal, ob die Grundsteuer bereits in der Hausgeldabrechnung oder einer anderen Kostenliste
                        enthalten ist. Bei einer möglichen Dublette wird nicht addiert, sondern eine Prüfaufgabe
                        erzeugt. Teilzeiträume und ein Eigentumswechsel werden nicht geraten, sondern Ihnen zur
                        Bestätigung vorgelegt.
                    </p>
                </x-hvm.faq-item>

                <x-hvm.faq-item question="Kann ich eine Gewerbeeinheit abrechnen?">
                    <p>
                        Der aktuelle Umfang ist auf die Wohnraummiete ausgelegt. Gewerbliche Mietverhältnisse sind im
                        Datenmodell vorbereitet, werden aber nicht stillschweigend nach Wohnraummietrecht abgerechnet.
                        Bei der Auswahl Gewerbe erscheint ein Hinweis und es erfolgt keine automatische Finalisierung.
                    </p>
                </x-hvm.faq-item>
            </div>
        </section>

        <section class="mt-14">
            <h2 class="text-2xl font-bold text-hvm-anthrazit">Preis, Zahlung und Rechnung</h2>

            <div class="mt-6 border-t border-hvm-hellgrau">
                <x-hvm.faq-item question="Was kostet die Nutzung?">
                    <p>
                        Der Preis beträgt {{ $preisBrutto }} brutto je erzeugter Mieterabrechnung. Konto, Objekte und
                        Entwürfe sind kostenlos. Abrechnungseinheit für den Preis ist die erzeugte Mieterabrechnung, bei
                        einem Mieterwechsel entstehen für eine Einheit mehrere Abrechnungen. Ein Rechenbeispiel finden
                        Sie auf der Seite
                        <a href="{{ route('site.preise') }}" class="underline underline-offset-2">Preise</a>.
                    </p>
                </x-hvm.faq-item>

                <x-hvm.faq-item question="Wann zahle ich?">
                    <p>
                        Erst nachdem Sie die Vorschau geprüft und bestätigt haben. Vor der Vorschau erhalten Sie eine
                        unverbindliche Schätzung, vor dem Bezahlvorgang wird der Endpreis anhand der tatsächlich
                        erzeugten Abrechnungen erneut berechnet. Die Abrechnungen ohne Wasserzeichen werden erst nach
                        bestätigter Zahlung freigegeben.
                    </p>
                </x-hvm.faq-item>

                <x-hvm.faq-item question="Erhalte ich eine Rechnung?">
                    <p>
                        Ja. Nach der Zahlung erstellt die {{ $operator['legal_name'] }} eine Rechnung über die
                        erbrachte Leistung. Netto, Umsatzsteuer und Brutto werden getrennt ausgewiesen. Die Rechnung
                        bleibt in Ihrem Konto abrufbar. Eine bereits erstellte Rechnung wird nicht überschrieben, eine
                        Korrektur erfolgt über eine Stornorechnung.
                    </p>
                </x-hvm.faq-item>

                <x-hvm.faq-item question="Entsteht ein Abonnement?">
                    <p>
                        Nein. Es gibt keine Laufzeit und keine automatische Verlängerung. Auch die Erinnerungsfunktion
                        für Folgejahre gehört zum kostenlosen Konto und löst keine Zahlung aus.
                    </p>
                </x-hvm.faq-item>

                <x-hvm.faq-item question="Was passiert, wenn ich nach der Zahlung einen Fehler entdecke?">
                    <p>
                        Eine finalisierte Abrechnung wird nie überschrieben. Eine Korrektur erzeugt eine neue Version,
                        die frühere Fassung bleibt als ersetzt erkennbar. Ob für die Korrektur erneut ein Betrag
                        anfällt, wird Ihnen vorher angezeigt und muss von Ihnen bestätigt werden.
                    </p>
                </x-hvm.faq-item>
            </div>
        </section>

        <section class="mt-14">
            <h2 class="text-2xl font-bold text-hvm-anthrazit">Ergebnis, Folgejahre und Erinnerungen</h2>

            <div class="mt-6 border-t border-hvm-hellgrau">
                <x-hvm.faq-item question="Was sehen meine Mieter?">
                    <p>
                        Ihre Mieter erhalten eine neutral gestaltete Betriebskostenabrechnung. Als Absender erscheinen
                        Ihr Name und Ihre Anschrift, nicht die {{ $operator['legal_name'] }}. Die Abrechnung enthält
                        Objekt, Einheit, Abrechnungszeitraum, Nutzungszeitraum, die Kostentabelle mit Gesamtkosten,
                        Schlüssel und Anteil, die Vorauszahlungen und das Ergebnis als Nachzahlung oder Guthaben.
                    </p>
                    <p class="mt-2">
                        In der Fußzeile steht ein dezenter Hinweis auf smart-abrechnen.de. Ein Logo der Hausverwaltung
                        erscheint in der Mieterabrechnung nicht.
                    </p>
                </x-hvm.faq-item>

                <x-hvm.faq-item question="Kann ich Daten für Folgejahre übernehmen?">
                    <p>
                        Ja. Mit einer neuen Abrechnung für das folgende Jahr übernimmt das Portal aus dem letzten
                        abgeschlossenen Lauf die Objekt- und Eigentümerdaten, die Einheiten mit Flächen und Schlüsseln,
                        die laufenden Mietverhältnisse, die Kostenkategorien und Ihre Absenderdaten. Übernommene Felder
                        sind sichtbar gekennzeichnet.
                    </p>
                    <p class="mt-2">
                        Neue Belege, Mieterwechsel, Vorauszahlungen, Zählerstände und Heizkosten müssen für das neue
                        Jahr erneut erfasst oder bestätigt werden. Vorjahreswerte werden nie als neue Kosten
                        übernommen.
                    </p>
                </x-hvm.faq-item>

                <x-hvm.faq-item question="Erhalte ich Erinnerungen?">
                    <p>
                        Ja, wenn Sie das möchten. Für jedes aktive Objekt erinnert das Portal an die noch offene
                        Abrechnung, voreingestellt am {{ $terminQ1 }}, am {{ $terminQ2 }} und am {{ $terminQ3 }} sowie
                        mit einem Fristhinweis am {{ $terminDezember }}. Ist der betreffende Jahreslauf abgeschlossen,
                        entfällt die Erinnerung.
                    </p>
                    <p class="mt-2">
                        Sie können Erinnerungen insgesamt oder je Objekt abschalten und später wieder einschalten. Jede
                        Erinnerungsmail enthält einen Abmeldelink. Wichtige Konto- und Zahlungsnachrichten bleiben davon
                        unberührt.
                    </p>
                </x-hvm.faq-item>

                <x-hvm.faq-item question="Ersetzt Smart Abrechnen einen Rechtsanwalt oder Steuerberater?">
                    <p>
                        Nein. Smart Abrechnen ist ein Software-Werkzeug. Es erstellt fachlich strukturierte
                        Abrechnungen und technische Plausibilitätsprüfungen. Absender und inhaltlich verantwortlich
                        bleibt der Vermieter. Für die rechtliche Beurteilung eines Einzelfalls wenden Sie sich an einen
                        Rechtsanwalt, für steuerliche Fragen an einen Steuerberater.
                    </p>
                </x-hvm.faq-item>
            </div>
        </section>

        <section class="mt-14">
            <x-hvm.card :accent="true" title="Ihre Frage war nicht dabei?">
                <p>
                    Schreiben Sie uns. Bitte senden Sie keine Belege oder Mietverträge per E-Mail, sondern laden Sie
                    Unterlagen ausschließlich im Portal hoch.
                </p>
                <div class="mt-4 flex flex-wrap gap-3">
                    <x-hvm.button href="{{ route('site.kontakt') }}" variant="primary">Zum Kontakt</x-hvm.button>
                    <x-hvm.button href="{{ url('/app') }}" variant="secondary">Kostenlos starten</x-hvm.button>
                </div>
            </x-hvm.card>
        </section>
    </div>
@endsection
