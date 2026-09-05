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

    // Themenbloecke fuer die Sprungliste im Seitenkopf.
    $themen = [
        ['thema-unterlagen', 'Unterlagen und Datenschutz', 'document'],
        ['thema-berechnung', 'Berechnung und Sonderfälle', 'layers'],
        ['thema-preis', 'Preis, Zahlung und Rechnung', 'euro'],
        ['thema-ergebnis', 'Ergebnis, Folgejahre und Erinnerungen', 'calendar'],
    ];
@endphp

@section('content')
    {{-- Seitenkopf Website (Designsystem 4.2) mit Sprungliste zu den Themen. --}}
    <section class="bg-hvm-canvas">
        <div class="mx-auto max-w-7xl px-4 pt-16 pb-20 sm:px-6 lg:px-8 lg:pt-24 lg:pb-28">
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-12 lg:items-end">
                <div class="min-w-0 lg:col-span-7">
                    <x-hvm.badge variant="akzent" :icon="false">Häufige Fragen</x-hvm.badge>
                    <h1 class="mt-6 text-4xl leading-[1.05] font-semibold tracking-tight text-hvm-textschwarz sm:text-5xl lg:text-6xl">
                        Antworten auf die Fragen, die am häufigsten gestellt werden
                    </h1>
                    <p class="mt-7 max-w-prose text-lg leading-relaxed text-hvm-text-sekundaer sm:text-xl">
                        Die Antworten sind fachliche Erläuterungen zur Funktionsweise des Portals. Eine Rechtsberatung im
                        Einzelfall ist damit nicht verbunden.
                    </p>
                </div>

                <nav class="min-w-0 lg:col-span-4 lg:col-start-9" aria-label="Themen der häufigen Fragen">
                    <x-hvm.card padding="none" class="rounded-3xl">
                        <p class="px-6 pt-5 text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">Vier Themen</p>
                        <ul class="mt-3 divide-y divide-hvm-linie">
                            @foreach ($themen as [$ziel, $titel, $icon])
                                <li>
                                    <a href="#{{ $ziel }}" class="flex min-h-11 items-center gap-4 px-6 py-3 text-sm font-medium text-hvm-textschwarz no-underline transition-colors hover:bg-hvm-canvas">
                                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark" aria-hidden="true">
                                            <x-hvm.icon :name="$icon" class="h-4 w-4" />
                                        </span>
                                        <span class="min-w-0 flex-1">{{ $titel }}</span>
                                        <x-hvm.icon name="chevron-right" class="h-4 w-4 shrink-0 text-hvm-text-sekundaer" />
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </x-hvm.card>
                </nav>
            </div>
        </div>
    </section>

    {{-- Thema 1: Unterlagen und Datenschutz --}}
    <section id="thema-unterlagen" class="border-y border-hvm-linie bg-white">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-12">
                <div class="min-w-0 lg:col-span-4">
                    <x-hvm.section-heading
                        level="h2"
                        eyebrow="Thema 1 von 4"
                        title="Unterlagen und Datenschutz" />
                </div>

                <div class="min-w-0 border-t border-hvm-linie lg:col-span-8">
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
                            <a href="{{ route('site.datenschutz-konzept') }}" class="font-medium underline decoration-hvm-orange decoration-2 underline-offset-4">Datenschutz und Löschung</a>.
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
            </div>
        </div>
    </section>

    {{-- Thema 2: Berechnung und Sonderfaelle --}}
    <section id="thema-berechnung" class="bg-hvm-canvas">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-12">
                <div class="min-w-0 lg:col-span-4">
                    <x-hvm.section-heading
                        level="h2"
                        eyebrow="Thema 2 von 4"
                        title="Berechnung und Sonderfälle" />
                </div>

                <div class="min-w-0 border-t border-hvm-linie lg:col-span-8">
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
            </div>
        </div>
    </section>

    {{-- Thema 3: Preis, Zahlung und Rechnung --}}
    <section id="thema-preis" class="border-y border-hvm-linie bg-white">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-12">
                <div class="min-w-0 lg:col-span-4">
                    <x-hvm.section-heading
                        level="h2"
                        eyebrow="Thema 3 von 4"
                        title="Preis, Zahlung und Rechnung" />
                </div>

                <div class="min-w-0 border-t border-hvm-linie lg:col-span-8">
                    <x-hvm.faq-item question="Was kostet die Nutzung?">
                        <p>
                            Der Preis beträgt {{ $preisBrutto }} brutto je erzeugter Mieterabrechnung. Konto, Objekte und
                            Entwürfe sind kostenlos. Abrechnungseinheit für den Preis ist die erzeugte Mieterabrechnung, bei
                            einem Mieterwechsel entstehen für eine Einheit mehrere Abrechnungen. Ein Rechenbeispiel finden
                            Sie auf der Seite
                            <a href="{{ route('site.preise') }}" class="font-medium underline decoration-hvm-orange decoration-2 underline-offset-4">Preise</a>.
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
            </div>
        </div>
    </section>

    {{-- Thema 4: Ergebnis, Folgejahre und Erinnerungen --}}
    <section id="thema-ergebnis" class="bg-hvm-canvas">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-12">
                <div class="min-w-0 lg:col-span-4">
                    <x-hvm.section-heading
                        level="h2"
                        eyebrow="Thema 4 von 4"
                        title="Ergebnis, Folgejahre und Erinnerungen" />
                </div>

                <div class="min-w-0 border-t border-hvm-linie lg:col-span-8">
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
            </div>
        </div>
    </section>

    {{-- Abschluss: Kontakt --}}
    <section class="border-t border-hvm-linie bg-white">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <div class="mx-auto max-w-3xl text-center">
                <span class="mx-auto block h-1 w-12 rounded-full bg-hvm-orange" aria-hidden="true"></span>
                <h2 class="mt-8 text-3xl font-semibold tracking-tight text-hvm-textschwarz sm:text-4xl">Ihre Frage war nicht dabei?</h2>
                <p class="mx-auto mt-6 max-w-prose text-lg leading-relaxed text-hvm-text-sekundaer">
                    Schreiben Sie uns. Bitte senden Sie keine Belege oder Mietverträge per E-Mail, sondern laden Sie
                    Unterlagen ausschließlich im Portal hoch.
                </p>
                <div class="mt-10 flex flex-wrap justify-center gap-3">
                    <x-hvm.button href="{{ route('site.kontakt') }}" variant="primary" size="lg">
                        Zum Kontakt
                        <x-hvm.icon name="arrow-right" class="h-5 w-5" />
                    </x-hvm.button>
                    <x-hvm.button href="{{ url('/app') }}" variant="secondary" size="lg">Kostenlos starten</x-hvm.button>
                </div>
            </div>
        </div>
    </section>
@endsection
