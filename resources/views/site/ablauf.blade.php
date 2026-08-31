@extends('layouts.site')

@section('meta_title', 'So funktioniert es')
@section('meta_description', 'Der geführte Ablauf von Smart Abrechnen in zwölf Schritten: hochladen, auswerten lassen, offene Punkte bestätigen, Vorschau prüfen, zahlen und die Final-PDFs erhalten.')

@php
    $ttlMinuten = (int) config('smartabrechnen.retention.temp_upload_ttl_minutes');
@endphp

@section('content')
    <section class="border-b border-hvm-umrissgrau bg-hvm-umrissgrau">
        <div class="mx-auto max-w-4xl px-4 py-12 sm:px-6 sm:py-16">
            <x-hvm.badge variant="akzent">Ablauf</x-hvm.badge>
            <h1 class="mt-5 text-3xl font-bold text-hvm-anthrazit sm:text-4xl">
                So entsteht Ihre Betriebskostenabrechnung
            </h1>
            <p class="mt-5 text-lg leading-relaxed text-hvm-textschwarz">
                Der Ablauf besteht aus zwölf Schritten in fünf Abschnitten. Jeder Schritt speichert automatisch. Sie
                können jederzeit unterbrechen und später ohne Datenverlust weiterarbeiten. Ihr Überblick zeigt statt
                technischer Meldungen eine klare Liste: erledigt, bitte prüfen, fehlt noch und blockiert die
                Abrechnung.
            </p>
        </div>
    </section>

    <div class="mx-auto max-w-4xl px-4 py-12 sm:px-6 sm:py-16">
        {{-- Abschnitt 1 --}}
        <section>
            <x-hvm.section-heading
                level="h2"
                eyebrow="Abschnitt 1 von 5"
                title="Vorbereiten und hochladen"
                lead="Sie legen den Abrechnungszeitraum fest und geben alle Unterlagen in einem Schritt ab." />

            <ol class="mt-8 space-y-8">
                <li>
                    <x-hvm.step number="1" title="Konto und Abrechnungsjahr">
                        <p>
                            Sie hinterlegen Name, E-Mail-Adresse und Rechnungsanschrift und wählen den
                            Abrechnungszeitraum. Voreingestellt ist der 01.01. bis 31.12. des Vorjahres. Anschließend
                            entscheiden Sie sich für die Schnellabrechnung einer Eigentumswohnung oder für die
                            vollständige Objektabrechnung. Eine Empfehlung erhalten Sie nach dem Upload.
                        </p>
                    </x-hvm.step>
                </li>
                <li>
                    <x-hvm.step number="2" title="Alles hochladen">
                        <p>
                            Eine zentrale Ablagefläche nimmt alle Dokumentarten gleichzeitig an, auch ungeordnet und
                            gemischt. Eine Kategorie können Sie angeben, müssen es aber nicht. Für jede Datei sehen Sie
                            den Upload und den Verarbeitungsstand.
                        </p>
                        <x-hvm.alert class="mt-4" variant="info" title="Umgang mit Ihren Dateien">
                            Ihre Originaldateien werden nur zur Auswertung kurzfristig verarbeitet und anschließend
                            automatisch gelöscht, spätestens nach {{ $ttlMinuten }} Minuten. Bitte bewahren Sie Ihre
                            Originalbelege selbst auf.
                        </x-hvm.alert>
                    </x-hvm.step>
                </li>
            </ol>
        </section>

        {{-- Abschnitt 2 --}}
        <section class="mt-16">
            <x-hvm.section-heading
                level="h2"
                eyebrow="Abschnitt 2 von 5"
                title="Automatisch auswerten lassen"
                lead="Das Portal ordnet Ihre Unterlagen ein und liest die benötigten Werte aus." />

            <ol class="mt-8 space-y-8" start="3">
                <li>
                    <x-hvm.step number="3" title="Automatische Analyse">
                        <p>
                            Eine Statusseite zeigt Ihnen den Fortschritt in verständlichen Angaben, zum Beispiel
                            zwölf Dokumente geprüft, drei Einheiten erkannt, 27 Kostenpositionen zugeordnet und zwei
                            Angaben müssen geprüft werden.
                        </p>
                        <p class="mt-3">
                            Zu jedem ausgelesenen Wert werden die Quelle, die Seite, ein kurzer Fundstellenausschnitt
                            und die Konfidenz gespeichert. Beträge und Mieteranteile werden nicht geschätzt, sondern
                            ausschließlich rechnerisch ermittelt.
                        </p>
                    </x-hvm.step>
                </li>
            </ol>
        </section>

        {{-- Abschnitt 3 --}}
        <section class="mt-16">
            <x-hvm.section-heading
                level="h2"
                eyebrow="Abschnitt 3 von 5"
                title="Offene Punkte bestätigen"
                lead="Sie prüfen nur, was unklar, widersprüchlich oder unvollständig ist." />

            <ol class="mt-8 space-y-8" start="4">
                <li>
                    <x-hvm.step number="4" title="Objekt, Vermieter und Einheiten">
                        <p>
                            Sie bestätigen die Objektanschrift, den Eigentümer oder Vermieter als Absender und die
                            Einheiten mit Bezeichnung, Lage, Wohnfläche, Miteigentumsanteil und individuellen Werten.
                            Leerstehende Einheiten werden ausdrücklich gekennzeichnet. Flächen- und Anteilssummen
                            werden auf Plausibilität geprüft.
                        </p>
                    </x-hvm.step>
                </li>
                <li>
                    <x-hvm.step number="5" title="Mietverhältnisse und Zeitachse">
                        <p>
                            Sie bestätigen Mieter, Zustellanschrift, Einzug, Auszug, Mieterwechsel und Leerstand. Eine
                            barrierearme Zeitachse je Einheit macht Überschneidungen und Lücken sichtbar. Der
                            Abrechnungszeitraum muss lückenlos belegt oder ausdrücklich als Leerstand gekennzeichnet
                            sein.
                        </p>
                    </x-hvm.step>
                </li>
                <li>
                    <x-hvm.step number="6" title="Kostenprüfung">
                        <p>
                            Die Kosten erscheinen nach Kategorie gruppiert und lassen sich bis zum einzelnen
                            Quelldokument aufklappen. Je Position sehen Sie Bezeichnung, Lieferant, Beleg- und
                            Leistungszeitraum, Betrag, vorgeschlagene Kategorie, Umlagebewertung, Quelle, Konfidenz und
                            mögliche Dubletten. Sie bestätigen, bearbeiten, schließen aus oder ordnen direkt einer
                            Einheit zu.
                        </p>
                        <p class="mt-3">
                            Eine Sammelbestätigung ist nur für konfliktfreie Positionen mit hoher Konfidenz möglich.
                            Nicht umlagefähige und unklare Positionen behandeln Sie einzeln.
                        </p>
                    </x-hvm.step>
                </li>
                <li>
                    <x-hvm.step number="7" title="Vorauszahlungen">
                        <p>
                            Je Mietverhältnis erfassen Sie die vereinbarte monatliche Vorauszahlung, eine getrennte
                            Heizkostenvorauszahlung, die Sollsumme für den Nutzungszeitraum und die tatsächlich
                            geleisteten Zahlungen. Liegen Kontoauszüge oder Zahlungslisten vor, werden Zahlungseingänge
                            zugeordnet und Abweichungen angezeigt.
                        </p>
                        <p class="mt-3">
                            Abgezogen werden die tatsächlich geleisteten Vorauszahlungen. Wenn Sie stattdessen die
                            Sollwerte übernehmen, bestätigen Sie das sichtbar als Annahme.
                        </p>
                    </x-hvm.step>
                </li>
                <li>
                    <x-hvm.step number="8" title="Verteilerschlüssel und Verbrauch">
                        <p>
                            Vorgeschlagen wird zuerst die bestätigte Regelung aus dem Mietvertrag, dann der bestätigte
                            Schlüssel des Vorjahres, danach ein fachlich naheliegender Standard mit Warnhinweis. Zur
                            Auswahl stehen unter anderem Wohnfläche, beheizte Wohnfläche, Miteigentumsanteile,
                            Personen, Personentage, Einheiten, Verbrauch und die direkte Zuordnung.
                        </p>
                        <p class="mt-3">
                            Für jede Kostenart sehen Sie den Wert Ihrer Einheit, den Gesamtnenner und den Rechenweg.
                            Der Schlüssel der Wohnungseigentümergemeinschaft und der mietvertragliche Umlageschlüssel
                            werden nicht stillschweigend gleichgesetzt.
                        </p>
                    </x-hvm.step>
                </li>
            </ol>
        </section>

        {{-- Abschnitt 4 --}}
        <section class="mt-16">
            <x-hvm.section-heading
                level="h2"
                eyebrow="Abschnitt 4 von 5"
                title="Prüfbericht und Vorschau"
                lead="Vor der Vorschau läuft die vollständige Prüfung. Danach sehen Sie das Ergebnis, noch ohne Zahlung." />

            <ol class="mt-8 space-y-8" start="9">
                <li>
                    <x-hvm.step number="9" title="Prüfbericht">
                        <p>Die Ergebnisse sind in vier Gruppen sortiert:</p>
                        <ul class="mt-3 space-y-2">
                            <li><x-hvm.badge variant="error">Blocker</x-hvm.badge> <span class="ml-2">Die Finalisierung ist nicht möglich.</span></li>
                            <li><x-hvm.badge variant="warning">Warnung</x-hvm.badge> <span class="ml-2">Ihre ausdrückliche Entscheidung ist erforderlich.</span></li>
                            <li><x-hvm.badge variant="info">Hinweis</x-hvm.badge> <span class="ml-2">Plausibel, aber gut zu wissen.</span></li>
                            <li><x-hvm.badge variant="success">Bestanden</x-hvm.badge> <span class="ml-2">Der Prüfschritt war erfolgreich.</span></li>
                        </ul>
                    </x-hvm.step>
                </li>
                <li>
                    <x-hvm.step number="10" title="Vorschau mit Wasserzeichen">
                        <p>
                            Alle Mieterabrechnungen und die Eigentümerübersicht werden erzeugt. Jede Vorschauseite
                            trägt ein großes diagonales Wasserzeichen, das nicht Teil der Browserdarstellung ist,
                            sondern fest in die Datei eingerechnet wird. Die Vorschau ist nur für Sie im angemeldeten
                            Zustand abrufbar.
                        </p>
                        <p class="mt-3">
                            Vor dem Bezahlvorgang bestätigen Sie, dass Sie alle Daten und Ergebnisse geprüft haben, die
                            Verantwortung als Vermieter übernehmen, den Preis und die Anzahl der Abrechnungen kennen
                            und die rechtlichen Pflichttexte akzeptieren.
                        </p>
                    </x-hvm.step>
                </li>
            </ol>
        </section>

        {{-- Abschnitt 5 --}}
        <section class="mt-16">
            <x-hvm.section-heading
                level="h2"
                eyebrow="Abschnitt 5 von 5"
                title="Zahlen und abschließen"
                lead="Erst die bestätigte Zahlung schaltet die Abrechnungen ohne Wasserzeichen frei." />

            <ol class="mt-8 space-y-8" start="11">
                <li>
                    <x-hvm.step number="11" title="Zahlung">
                        <p>
                            Der Endpreis wird vor dem Bezahlvorgang anhand der tatsächlich erzeugten Mieterabrechnungen
                            erneut berechnet. Die Zahlung läuft über die gesicherte Bezahlseite eines
                            Zahlungsdienstleisters. Freigeschaltet wird erst, wenn die Zahlung dort bestätigt ist. Die
                            Rückleitung in den Browser allein genügt nicht.
                        </p>
                    </x-hvm.step>
                </li>
                <li>
                    <x-hvm.step number="12" title="Finalisierung">
                        <p>
                            Der bezahlte Berechnungsstand wird unveränderlich gesichert. Danach werden alle PDFs ohne
                            Wasserzeichen neu erzeugt. Sie erhalten:
                        </p>
                        <ul class="mt-3 list-disc space-y-1 pl-5">
                            <li>eine Mieterabrechnung je Mietverhältnis als einzelne Datei</li>
                            <li>alle Abrechnungen zusammen als ZIP-Datei</li>
                            <li>die Eigentümerübersicht mit Leerstandsanteilen und ausgeschlossenen Kosten</li>
                            <li>die Rechnung der {{ config('smartabrechnen.operator.legal_name') }}</li>
                            <li>eine Bestätigungs-E-Mail mit einem gesicherten Download-Link</li>
                        </ul>
                        <p class="mt-3">
                            Eine finalisierte Abrechnung wird nie überschrieben. Eine Korrektur erzeugt eine neue
                            Version, die frühere Fassung bleibt als ersetzt erkennbar.
                        </p>
                    </x-hvm.step>
                </li>
            </ol>
        </section>

        <section class="mt-16">
            <x-hvm.alert variant="warning" title="Ihre Verantwortung bleibt">
                <p>
                    Absender und inhaltlich verantwortlich für die Betriebskostenabrechnung ist der Vermieter. Smart
                    Abrechnen ist ein Software-Werkzeug und erbringt keine Rechtsberatung im Einzelfall. Bei streitigen
                    Fragen wenden Sie sich an einen Rechtsanwalt oder Steuerberater.
                </p>
            </x-hvm.alert>

            <div class="mt-8 flex flex-wrap gap-3">
                <x-hvm.button href="{{ url('/app') }}" variant="primary" size="lg">Kostenlos starten</x-hvm.button>
                <x-hvm.button href="{{ route('site.preise') }}" variant="secondary" size="lg">Preise ansehen</x-hvm.button>
            </div>
        </section>
    </div>
@endsection
