@extends('layouts.site')

@section('meta_title', 'Datenschutz und Löschung')
@section('meta_description', 'Wie Smart Abrechnen mit Ihren Unterlagen umgeht: kurzfristige Verarbeitung im verschlüsselten temporären Bereich, automatische Löschung der Originale, dauerhaft nur die ausgelesenen Inhaltsdaten.')

@php
    $ttlMinuten = (int) config('smartabrechnen.retention.temp_upload_ttl_minutes');
    $providerTtlMinuten = (int) config('smartabrechnen.retention.ai_provider_file_ttl_minutes');
@endphp

@section('content')
    <section class="border-b border-hvm-umrissgrau bg-hvm-umrissgrau">
        <div class="mx-auto max-w-4xl px-4 py-12 sm:px-6 sm:py-16">
            <x-hvm.badge variant="akzent">Datenschutz und Löschung</x-hvm.badge>
            <h1 class="mt-5 text-3xl font-bold text-hvm-anthrazit sm:text-4xl">
                Ihre Originaldateien werden nach der Auswertung gelöscht
            </h1>
            <p class="mt-5 text-lg leading-relaxed text-hvm-textschwarz">
                Für eine Betriebskostenabrechnung braucht das Portal die Werte aus Ihren Unterlagen, nicht die
                Unterlagen selbst. Deshalb werden Ihre Originaldateien nur kurzfristig verarbeitet und anschließend
                automatisch gelöscht. Dauerhaft bleiben ausschließlich die ausgelesenen Inhaltsdaten.
            </p>
            <p class="mt-4 text-base text-hvm-textschwarz">
                Diese Seite erklärt das Konzept in verständlicher Form. Sie ist keine Datenschutzerklärung und ersetzt
                sie nicht. Die
                <a href="{{ route('legal.datenschutz') }}" class="underline underline-offset-2">Datenschutzerklärung</a>
                wird vor dem Livegang anwaltlich geprüft und freigegeben.
            </p>
        </div>
    </section>

    <div class="mx-auto max-w-4xl px-4 py-12 sm:px-6 sm:py-16">
        {{-- Weg der Datei --}}
        <section>
            <x-hvm.section-heading
                level="h2"
                eyebrow="Der Weg einer Datei"
                title="Vom Upload bis zur Löschung"
                lead="Jede hochgeladene Datei durchläuft denselben nachvollziehbaren Weg." />

            <ol class="mt-8 space-y-8">
                <li>
                    <x-hvm.step number="1" title="Ablage in einem verschlüsselten temporären Bereich">
                        <p>
                            Der Upload landet in einem zufällig benannten, verschlüsselten Arbeitsbereich außerhalb des
                            öffentlich erreichbaren Verzeichnisses. Dieser Bereich ist technisch von dem Speicher
                            getrennt, auf dem später die erzeugten Abrechnungen liegen.
                        </p>
                    </x-hvm.step>
                </li>
                <li>
                    <x-hvm.step number="2" title="Prüfung und Auswertung">
                        <p>
                            Die Datei wird auf Dateityp, Größe und Struktur geprüft, eingeordnet und ausgewertet. Aus
                            dem Inhalt werden nur die für die Abrechnung erforderlichen Werte übernommen, jeweils mit
                            Angabe der Quelle, der Seite und eines kurzen Fundstellenausschnitts.
                        </p>
                    </x-hvm.step>
                </li>
                <li>
                    <x-hvm.step number="3" title="Automatische Löschung der Originale">
                        <p>
                            Unmittelbar nach der abgeschlossenen Auswertung werden die Originaldatei, erzeugte
                            Seitendarstellungen, Zwischendateien und der vollständige ausgelesene Text gelöscht.
                            Dasselbe geschieht, wenn die Verarbeitung endgültig fehlschlägt. Für einen neuen Versuch
                            laden Sie die Datei erneut hoch.
                        </p>
                    </x-hvm.step>
                </li>
                <li>
                    <x-hvm.step number="4" title="Unabhängige Aufräumung nach kurzer Frist">
                        <p>
                            Ein eigener Aufräumvorgang löscht überfällige Dateien auch dann, wenn eine Verarbeitung
                            abgebrochen oder hängen geblieben ist. Die Frist beginnt mit dem Eingang des ersten
                            Uploadteils und beträgt höchstens {{ $ttlMinuten }} Minuten.
                        </p>
                    </x-hvm.step>
                </li>
                <li>
                    <x-hvm.step number="5" title="Nachweis der Löschung">
                        <p>
                            Zu jeder Löschung werden Dokumentkennung, Zeitpunkt und Status protokolliert, ohne
                            Dateiinhalte zu speichern. Bleibt eine Löschung aus, erscheint das im internen
                            Datenschutzmonitor als vorrangig zu bearbeitender Punkt.
                        </p>
                    </x-hvm.step>
                </li>
            </ol>
        </section>

        {{-- Was bleibt, was nicht --}}
        <section class="mt-14">
            <x-hvm.section-heading
                level="h2"
                eyebrow="Speicherung"
                title="Was dauerhaft bleibt und was nicht" />

            <div class="mt-6 grid gap-6 lg:grid-cols-2">
                <x-hvm.card title="Dauerhaft gespeichert">
                    <ul class="list-disc space-y-1 pl-5">
                        <li>Dokumentart und eine neutrale Quellenbezeichnung, zum Beispiel Dokument 01 Grundsteuerbescheid</li>
                        <li>Rechnungssteller oder Behörde, soweit für die Abrechnung erforderlich</li>
                        <li>Datum, Leistungszeitraum und Betrag</li>
                        <li>Kostenart und vorgeschlagene Umlagebehandlung</li>
                        <li>Objekt, Einheit, Mieter, Fläche, Verteilerschlüssel und Vorauszahlungen</li>
                        <li>Zählernummer und Zählerstand, soweit erforderlich</li>
                        <li>Seite, Feldbezeichnung und ein kurzer Fundstellenausschnitt als Nachweis</li>
                        <li>Ihre Bestätigungen und Korrekturen</li>
                        <li>die erzeugten Abrechnungen und die Rechnung</li>
                    </ul>
                </x-hvm.card>

                <x-hvm.card title="Nicht dauerhaft gespeichert">
                    <ul class="list-disc space-y-1 pl-5">
                        <li>Ihre Original-PDFs, Bilder, Tabellen und Archivdateien</li>
                        <li>vollständige Seitendarstellungen oder Vorschaubilder Ihrer Unterlagen</li>
                        <li>der vollständige ausgelesene Text einer Datei</li>
                        <li>Kameradaten und andere nicht benötigte Metadaten</li>
                        <li>die vollständigen Anfragen und Antworten der Auswertung</li>
                        <li>der ursprüngliche Dateiname</li>
                    </ul>
                    <p class="mt-4">
                        Ein dauerhaftes Belegarchiv wird bewusst nicht angeboten. Es gibt daher auch keine Option, die
                        Originalbelege im Konto aufzubewahren.
                    </p>
                </x-hvm.card>
            </div>
        </section>

        {{-- Backups und Ergebnisspeicher --}}
        <section class="mt-14">
            <x-hvm.section-heading
                level="h2"
                eyebrow="Speicherorte"
                title="Keine Originale in Backups oder auf dem Ergebnisspeicher" />

            <div class="mt-6 space-y-4">
                <x-hvm.card title="Getrennte Bereiche">
                    Der temporäre Arbeitsbereich und der Speicher für die erzeugten Abrechnungen sind getrennt.
                    Originaldateien werden auf dem Ergebnisspeicher nicht abgelegt. Dort liegen nur die vom Portal
                    erzeugten Dateien, also Vorschauen, Final-PDFs, ZIP-Dateien und Rechnungen.
                </x-hvm.card>

                <x-hvm.card title="Ausschluss aus Backups">
                    Der temporäre Arbeitsbereich wird aus den Datei- und Serverbackups ausgeschlossen. Gesichert werden
                    die Anwendungsdaten und die erzeugten Ergebnisdateien, nicht Ihre Originalunterlagen.
                </x-hvm.card>

                <x-hvm.card title="Zugriff auf Ergebnisdateien">
                    Vorschauen und Final-PDFs sind nur im angemeldeten Zustand oder über einen zeitlich begrenzten Link
                    erreichbar. Vorschauseiten tragen ein fest eingerechnetes Wasserzeichen. Ein Wasserzeichen ist ein
                    wirksames Hemmnis, aber keine technische Kopiersperre.
                </x-hvm.card>
            </div>
        </section>

        {{-- KI-Auswertung --}}
        <section class="mt-14">
            <x-hvm.section-heading
                level="h2"
                eyebrow="Auswertung"
                title="Einsatz von KI-Diensten, offen dargestellt"
                lead="Für die Einordnung der Dokumente und das Auslesen der Werte wird ein KI-Dienst eingesetzt. Das soll transparent bleiben." />

            <div class="mt-6 space-y-4">
                <x-hvm.card title="Was übermittelt wird">
                    Für die Auswertung werden Inhalte der hochgeladenen Unterlagen an einen KI-Dienst übermittelt. Die
                    Übermittlung erfolgt für den einzelnen Auswertungsvorgang und nicht zum Aufbau eines dauerhaften
                    Wissensspeichers.
                </x-hvm.card>

                <x-hvm.card title="Löschung beim Dienstleister">
                    Legt der Dienst für die Verarbeitung temporäre Dateien an, werden diese nach der Auswertung über
                    seine Löschschnittstelle gelöscht, spätestens nach {{ $providerTtlMinuten }} Minuten. Der
                    Löschstatus wird protokolliert und intern überwacht.
                </x-hvm.card>

                <x-hvm.card title="Was die KI nicht tut">
                    Geldbeträge und Mieteranteile werden nicht von der KI bestimmt, sondern ausschließlich durch festen
                    Programmcode berechnet. Die KI dient der Einordnung, dem Auslesen, der Zuordnung und der
                    Plausibilisierung. Fehlende Werte werden nicht geschätzt.
                </x-hvm.card>

                <x-hvm.card title="Vertragliche Grundlagen">
                    Der eingesetzte Dienst, die Auftragsverarbeitung und die Datenschutzentscheidung werden in der
                    Datenschutzerklärung benannt. Diese Angaben werden vor dem Livegang festgelegt und anwaltlich
                    freigegeben.
                </x-hvm.card>
            </div>
        </section>

        {{-- Ihre Aufbewahrung --}}
        <section class="mt-14">
            <x-hvm.section-heading
                level="h2"
                eyebrow="Ihre Aufgabe"
                title="Bitte bewahren Sie Ihre Originalbelege selbst auf" />

            <div class="mt-6 space-y-4">
                <x-hvm.alert variant="warning" title="Aufbewahrung liegt bei Ihnen">
                    <p>
                        Da das Portal Ihre Originaldateien nicht dauerhaft speichert, bleiben Sie für die Aufbewahrung
                        verantwortlich. Halten Sie Rechnungen, Bescheide, Heizkostenabrechnungen und Mietverträge
                        bereit, damit Sie Ihren Mietern die Belegeinsicht ermöglichen können und Ihre steuerlichen
                        Pflichten erfüllen.
                    </p>
                    <p class="mt-2">
                        Möchten Sie einen ausgelesenen Wert später nachvollziehen, vergleichen Sie ihn mit Ihrem
                        Original oder laden Sie die Datei erneut zur kurzfristigen Auswertung hoch.
                    </p>
                </x-hvm.alert>

                <x-hvm.alert variant="info" title="Klarstellung zur Löschung">
                    <p>
                        Verbindlich sind die logische Löschung der Dateien, der Ausschluss aus Backups, die kurze
                        Aufbewahrungsdauer und ein dokumentierter Löschstatus. Es wird nicht behauptet, dass Dateien auf
                        gemeinsam genutztem oder SSD-basiertem Speicher forensisch überschrieben werden.
                    </p>
                </x-hvm.alert>
            </div>
        </section>

        {{-- Konto --}}
        <section class="mt-14">
            <x-hvm.section-heading
                level="h2"
                eyebrow="Ihr Konto"
                title="Auskunft, Export und Löschung" />

            <div class="mt-6 grid gap-6 sm:grid-cols-2">
                <x-hvm.card title="Datenexport">
                    Sie können die zu Ihrem Konto gespeicherten Daten als Archivdatei exportieren. Enthalten sind die
                    ausgelesenen Inhaltsdaten und die erzeugten Abrechnungen, nicht Ihre Originalunterlagen, da diese
                    bereits gelöscht sind.
                </x-hvm.card>

                <x-hvm.card title="Kontolöschung">
                    Sie können die Löschung Ihres Kontos veranlassen. Rechnungen, die aus steuer- und
                    handelsrechtlichen Gründen aufzubewahren sind, werden vom gelöschten Konto entkoppelt und nur im
                    erforderlichen Umfang weiter aufbewahrt.
                </x-hvm.card>
            </div>

            <p class="mt-6 text-base text-hvm-textschwarz">
                Im aktuellen Umfang werden keine Analyse- oder Marketingtracker eingesetzt. Technisch notwendige
                Cookies werden in der Datenschutzerklärung dokumentiert.
            </p>

            <div class="mt-8 flex flex-wrap gap-3">
                <x-hvm.button href="{{ route('site.faq') }}" variant="secondary">Häufige Fragen</x-hvm.button>
                <x-hvm.button href="{{ route('site.kontakt') }}" variant="ghost">Frage zum Datenschutz stellen</x-hvm.button>
            </div>
        </section>
    </div>
@endsection
