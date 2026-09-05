@extends('layouts.site')

@section('meta_title', 'Datenschutz und Löschung')
@section('meta_description', 'Wie Smart Abrechnen mit Ihren Unterlagen umgeht: kurzfristige Verarbeitung im verschlüsselten temporären Bereich, automatische Löschung der Originale, dauerhaft nur die ausgelesenen Inhaltsdaten.')

@php
    $ttlMinuten = (int) config('smartabrechnen.retention.temp_upload_ttl_minutes');
    $providerTtlMinuten = (int) config('smartabrechnen.retention.ai_provider_file_ttl_minutes');
@endphp

@section('content')
    {{-- Seitenkopf Website (Designsystem 4.2). --}}
    <section class="bg-hvm-canvas">
        <div class="mx-auto max-w-7xl px-4 pt-16 pb-20 sm:px-6 lg:px-8 lg:pt-24 lg:pb-28">
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-12 lg:items-end">
                <div class="min-w-0 lg:col-span-7">
                    <x-hvm.badge variant="akzent" :icon="false">Datenschutz und Löschung</x-hvm.badge>
                    <h1 class="mt-6 text-4xl leading-[1.05] font-semibold tracking-tight text-hvm-textschwarz sm:text-5xl lg:text-6xl">
                        Ihre Originaldateien werden nach der Auswertung gelöscht
                    </h1>
                    <p class="mt-7 max-w-prose text-lg leading-relaxed text-hvm-text-sekundaer sm:text-xl">
                        Für eine Betriebskostenabrechnung braucht das Portal die Werte aus Ihren Unterlagen, nicht die
                        Unterlagen selbst. Deshalb werden Ihre Originaldateien nur kurzfristig verarbeitet und anschließend
                        automatisch gelöscht. Dauerhaft bleiben ausschließlich die ausgelesenen Inhaltsdaten.
                    </p>
                </div>

                <div class="min-w-0 lg:col-span-4 lg:col-start-9">
                    <x-hvm.alert variant="info" title="Einordnung">
                        Diese Seite erklärt das Konzept in verständlicher Form. Sie ist keine Datenschutzerklärung und ersetzt
                        sie nicht. Die
                        <a href="{{ route('legal.datenschutz') }}" class="font-medium underline decoration-hvm-orange decoration-2 underline-offset-4">Datenschutzerklärung</a>
                        wird vor dem Livegang anwaltlich geprüft und freigegeben.
                    </x-hvm.alert>
                </div>
            </div>
        </div>
    </section>

    {{-- Weg der Datei --}}
    <section class="border-y border-hvm-linie bg-white">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-12">
                <div class="min-w-0 lg:col-span-4">
                    <x-hvm.section-heading
                        level="h2"
                        eyebrow="Der Weg einer Datei"
                        title="Vom Upload bis zur Löschung"
                        lead="Jede hochgeladene Datei durchläuft denselben nachvollziehbaren Weg." />
                </div>

                <ol class="min-w-0 divide-y divide-hvm-linie lg:col-span-8">
                    <li class="py-7 first:pt-0 last:pb-0">
                        <x-hvm.step number="1" title="Ablage in einem verschlüsselten temporären Bereich">
                            <p>
                                Der Upload landet in einem zufällig benannten, verschlüsselten Arbeitsbereich außerhalb des
                                öffentlich erreichbaren Verzeichnisses. Dieser Bereich ist technisch von dem Speicher
                                getrennt, auf dem später die erzeugten Abrechnungen liegen.
                            </p>
                        </x-hvm.step>
                    </li>
                    <li class="py-7 first:pt-0 last:pb-0">
                        <x-hvm.step number="2" title="Prüfung und Auswertung">
                            <p>
                                Die Datei wird auf Dateityp, Größe und Struktur geprüft, eingeordnet und ausgewertet. Aus
                                dem Inhalt werden nur die für die Abrechnung erforderlichen Werte übernommen, jeweils mit
                                Angabe der Quelle, der Seite und eines kurzen Fundstellenausschnitts.
                            </p>
                        </x-hvm.step>
                    </li>
                    <li class="py-7 first:pt-0 last:pb-0">
                        <x-hvm.step number="3" title="Automatische Löschung der Originale">
                            <p>
                                Unmittelbar nach der abgeschlossenen Auswertung werden die Originaldatei, erzeugte
                                Seitendarstellungen, Zwischendateien und der vollständige ausgelesene Text gelöscht.
                                Dasselbe geschieht, wenn die Verarbeitung endgültig fehlschlägt. Für einen neuen Versuch
                                laden Sie die Datei erneut hoch.
                            </p>
                        </x-hvm.step>
                    </li>
                    <li class="py-7 first:pt-0 last:pb-0">
                        <x-hvm.step number="4" title="Unabhängige Aufräumung nach kurzer Frist">
                            <p>
                                Ein eigener Aufräumvorgang löscht überfällige Dateien auch dann, wenn eine Verarbeitung
                                abgebrochen oder hängen geblieben ist. Die Frist beginnt mit dem Eingang des ersten
                                Uploadteils und beträgt höchstens {{ $ttlMinuten }} Minuten.
                            </p>
                        </x-hvm.step>
                    </li>
                    <li class="py-7 first:pt-0 last:pb-0">
                        <x-hvm.step number="5" title="Nachweis der Löschung">
                            <p>
                                Zu jeder Löschung werden Dokumentkennung, Zeitpunkt und Status protokolliert, ohne
                                Dateiinhalte zu speichern. Bleibt eine Löschung aus, erscheint das im internen
                                Datenschutzmonitor als vorrangig zu bearbeitender Punkt.
                            </p>
                        </x-hvm.step>
                    </li>
                </ol>
            </div>
        </div>
    </section>

    {{-- Was bleibt, was nicht --}}
    <section class="bg-hvm-canvas">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <x-hvm.section-heading
                level="h2"
                eyebrow="Speicherung"
                title="Was dauerhaft bleibt und was nicht" />

            <div class="mt-14 grid grid-cols-1 gap-6 lg:grid-cols-2">
                <x-hvm.card class="min-w-0 rounded-3xl p-7 sm:p-9">
                    <div class="flex items-center gap-4">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-status-success-soft text-status-success" aria-hidden="true"><x-hvm.icon name="check-circle" /></span>
                        <h3 class="text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl">Dauerhaft gespeichert</h3>
                    </div>
                    <ul class="mt-6 space-y-3">
                        <li class="flex gap-3"><span class="mt-0.5 text-status-success" aria-hidden="true"><x-hvm.icon name="check" /></span><span>Dokumentart und eine neutrale Quellenbezeichnung, zum Beispiel Dokument 01 Grundsteuerbescheid</span></li>
                        <li class="flex gap-3"><span class="mt-0.5 text-status-success" aria-hidden="true"><x-hvm.icon name="check" /></span><span>Rechnungssteller oder Behörde, soweit für die Abrechnung erforderlich</span></li>
                        <li class="flex gap-3"><span class="mt-0.5 text-status-success" aria-hidden="true"><x-hvm.icon name="check" /></span><span>Datum, Leistungszeitraum und Betrag</span></li>
                        <li class="flex gap-3"><span class="mt-0.5 text-status-success" aria-hidden="true"><x-hvm.icon name="check" /></span><span>Kostenart und vorgeschlagene Umlagebehandlung</span></li>
                        <li class="flex gap-3"><span class="mt-0.5 text-status-success" aria-hidden="true"><x-hvm.icon name="check" /></span><span>Objekt, Einheit, Mieter, Fläche, Verteilerschlüssel und Vorauszahlungen</span></li>
                        <li class="flex gap-3"><span class="mt-0.5 text-status-success" aria-hidden="true"><x-hvm.icon name="check" /></span><span>Zählernummer und Zählerstand, soweit erforderlich</span></li>
                        <li class="flex gap-3"><span class="mt-0.5 text-status-success" aria-hidden="true"><x-hvm.icon name="check" /></span><span>Seite, Feldbezeichnung und ein kurzer Fundstellenausschnitt als Nachweis</span></li>
                        <li class="flex gap-3"><span class="mt-0.5 text-status-success" aria-hidden="true"><x-hvm.icon name="check" /></span><span>Ihre Bestätigungen und Korrekturen</span></li>
                        <li class="flex gap-3"><span class="mt-0.5 text-status-success" aria-hidden="true"><x-hvm.icon name="check" /></span><span>die erzeugten Abrechnungen und die Rechnung</span></li>
                    </ul>
                </x-hvm.card>

                <x-hvm.card class="min-w-0 rounded-3xl p-7 sm:p-9">
                    <div class="flex items-center gap-4">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-hvm-canvas-deep text-hvm-text-sekundaer" aria-hidden="true"><x-hvm.icon name="trash" /></span>
                        <h3 class="text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl">Nicht dauerhaft gespeichert</h3>
                    </div>
                    <ul class="mt-6 space-y-3">
                        <li class="flex gap-3"><span class="mt-0.5 text-hvm-text-sekundaer" aria-hidden="true"><x-hvm.icon name="x" /></span><span>Ihre Original-PDFs, Bilder, Tabellen und Archivdateien</span></li>
                        <li class="flex gap-3"><span class="mt-0.5 text-hvm-text-sekundaer" aria-hidden="true"><x-hvm.icon name="x" /></span><span>vollständige Seitendarstellungen oder Vorschaubilder Ihrer Unterlagen</span></li>
                        <li class="flex gap-3"><span class="mt-0.5 text-hvm-text-sekundaer" aria-hidden="true"><x-hvm.icon name="x" /></span><span>der vollständige ausgelesene Text einer Datei</span></li>
                        <li class="flex gap-3"><span class="mt-0.5 text-hvm-text-sekundaer" aria-hidden="true"><x-hvm.icon name="x" /></span><span>Kameradaten und andere nicht benötigte Metadaten</span></li>
                        <li class="flex gap-3"><span class="mt-0.5 text-hvm-text-sekundaer" aria-hidden="true"><x-hvm.icon name="x" /></span><span>die vollständigen Anfragen und Antworten der Auswertung</span></li>
                        <li class="flex gap-3"><span class="mt-0.5 text-hvm-text-sekundaer" aria-hidden="true"><x-hvm.icon name="x" /></span><span>der ursprüngliche Dateiname</span></li>
                    </ul>
                    <p class="mt-6 border-t border-hvm-linie pt-5 text-sm leading-relaxed text-hvm-text-sekundaer">
                        Ein dauerhaftes Belegarchiv wird bewusst nicht angeboten. Es gibt daher auch keine Option, die
                        Originalbelege im Konto aufzubewahren.
                    </p>
                </x-hvm.card>
            </div>
        </div>
    </section>

    {{-- Backups und Ergebnisspeicher --}}
    <section class="border-y border-hvm-linie bg-white">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-12">
                <div class="min-w-0 lg:col-span-4">
                    <x-hvm.section-heading
                        level="h2"
                        eyebrow="Speicherorte"
                        title="Keine Originale in Backups oder auf dem Ergebnisspeicher" />
                </div>

                <div class="min-w-0 lg:col-span-8">
                    <x-hvm.card tone="canvas" padding="none" class="divide-y divide-hvm-linie rounded-3xl">
                        <div class="flex gap-5 p-6 sm:p-7">
                            <span class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="layers" /></span>
                            <div class="min-w-0">
                                <h3 class="text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl">Getrennte Bereiche</h3>
                                <p class="mt-2 text-base leading-relaxed text-hvm-text-sekundaer">
                                    Der temporäre Arbeitsbereich und der Speicher für die erzeugten Abrechnungen sind getrennt.
                                    Originaldateien werden auf dem Ergebnisspeicher nicht abgelegt. Dort liegen nur die vom Portal
                                    erzeugten Dateien, also Vorschauen, Final-PDFs, ZIP-Dateien und Rechnungen.
                                </p>
                            </div>
                        </div>
                        <div class="flex gap-5 p-6 sm:p-7">
                            <span class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="shield" /></span>
                            <div class="min-w-0">
                                <h3 class="text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl">Ausschluss aus Backups</h3>
                                <p class="mt-2 text-base leading-relaxed text-hvm-text-sekundaer">
                                    Der temporäre Arbeitsbereich wird aus den Datei- und Serverbackups ausgeschlossen. Gesichert werden
                                    die Anwendungsdaten und die erzeugten Ergebnisdateien, nicht Ihre Originalunterlagen.
                                </p>
                            </div>
                        </div>
                        <div class="flex gap-5 p-6 sm:p-7">
                            <span class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="lock" /></span>
                            <div class="min-w-0">
                                <h3 class="text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl">Zugriff auf Ergebnisdateien</h3>
                                <p class="mt-2 text-base leading-relaxed text-hvm-text-sekundaer">
                                    Vorschauen und Final-PDFs sind nur im angemeldeten Zustand oder über einen zeitlich begrenzten Link
                                    erreichbar. Vorschauseiten tragen ein fest eingerechnetes Wasserzeichen. Ein Wasserzeichen ist ein
                                    wirksames Hemmnis, aber keine technische Kopiersperre.
                                </p>
                            </div>
                        </div>
                    </x-hvm.card>
                </div>
            </div>
        </div>
    </section>

    {{--
        KI-Auswertung als dunkle Flaeche (.hvm-dark). Karten und Ueberschrift
        passen sich automatisch an, die Kennlinie schliesst die Sektion oben ab.
    --}}
    <section class="hvm-dark">
        <div class="hvm-kennlinie" aria-hidden="true"></div>
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <x-hvm.section-heading
                level="h2"
                eyebrow="Auswertung"
                title="Einsatz von KI-Diensten, offen dargestellt"
                lead="Für die Einordnung der Dokumente und das Auslesen der Werte wird ein KI-Dienst eingesetzt. Das soll transparent bleiben." />

            <div class="mt-14 grid grid-cols-1 gap-5 md:grid-cols-2">
                <x-hvm.card class="min-w-0">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-hvm-graphit text-hvm-orange" aria-hidden="true"><x-hvm.icon name="upload" /></span>
                    <h3 class="mt-5 text-lg font-semibold tracking-tight text-white sm:text-xl">Was übermittelt wird</h3>
                    <p class="mt-3 text-base leading-relaxed text-hvm-hellgrau">
                        Für die Auswertung werden Inhalte der hochgeladenen Unterlagen an einen KI-Dienst übermittelt. Die
                        Übermittlung erfolgt für den einzelnen Auswertungsvorgang und nicht zum Aufbau eines dauerhaften
                        Wissensspeichers.
                    </p>
                </x-hvm.card>

                <x-hvm.card class="min-w-0">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-hvm-graphit text-hvm-orange" aria-hidden="true"><x-hvm.icon name="clock" /></span>
                    <h3 class="mt-5 text-lg font-semibold tracking-tight text-white sm:text-xl">Löschung beim Dienstleister</h3>
                    <p class="mt-3 text-base leading-relaxed text-hvm-hellgrau">
                        Legt der Dienst für die Verarbeitung temporäre Dateien an, werden diese nach der Auswertung über
                        seine Löschschnittstelle gelöscht, spätestens nach {{ $providerTtlMinuten }} Minuten. Der
                        Löschstatus wird protokolliert und intern überwacht.
                    </p>
                </x-hvm.card>

                <x-hvm.card class="min-w-0">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-hvm-graphit text-hvm-orange" aria-hidden="true"><x-hvm.icon name="sparkle" /></span>
                    <h3 class="mt-5 text-lg font-semibold tracking-tight text-white sm:text-xl">Was die KI nicht tut</h3>
                    <p class="mt-3 text-base leading-relaxed text-hvm-hellgrau">
                        Geldbeträge und Mieteranteile werden nicht von der KI bestimmt, sondern ausschließlich durch festen
                        Programmcode berechnet. Die KI dient der Einordnung, dem Auslesen, der Zuordnung und der
                        Plausibilisierung. Fehlende Werte werden nicht geschätzt.
                    </p>
                </x-hvm.card>

                <x-hvm.card class="min-w-0">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-hvm-graphit text-hvm-orange" aria-hidden="true"><x-hvm.icon name="document" /></span>
                    <h3 class="mt-5 text-lg font-semibold tracking-tight text-white sm:text-xl">Vertragliche Grundlagen</h3>
                    <p class="mt-3 text-base leading-relaxed text-hvm-hellgrau">
                        Der eingesetzte Dienst, die Auftragsverarbeitung und die Datenschutzentscheidung werden in der
                        Datenschutzerklärung benannt. Diese Angaben werden vor dem Livegang festgelegt und anwaltlich
                        freigegeben.
                    </p>
                </x-hvm.card>
            </div>
        </div>
    </section>

    {{-- Ihre Aufbewahrung --}}
    <section class="bg-hvm-canvas">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-12">
                <div class="min-w-0 lg:col-span-4">
                    <x-hvm.section-heading
                        level="h2"
                        eyebrow="Ihre Aufgabe"
                        title="Bitte bewahren Sie Ihre Originalbelege selbst auf" />
                </div>

                <div class="min-w-0 space-y-4 lg:col-span-8">
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
            </div>
        </div>
    </section>

    {{-- Konto --}}
    <section class="border-t border-hvm-linie bg-white">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-12">
                <div class="min-w-0 lg:col-span-4">
                    <x-hvm.section-heading
                        level="h2"
                        eyebrow="Ihr Konto"
                        title="Auskunft, Export und Löschung" />
                    <p class="mt-6 max-w-prose text-base leading-relaxed text-hvm-text-sekundaer">
                        Im aktuellen Umfang werden keine Analyse- oder Marketingtracker eingesetzt. Technisch notwendige
                        Cookies werden in der Datenschutzerklärung dokumentiert.
                    </p>
                </div>

                <div class="min-w-0 lg:col-span-8">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <x-hvm.card tone="canvas" class="min-w-0">
                            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="inbox" /></span>
                            <h3 class="mt-5 text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl">Datenexport</h3>
                            <p class="mt-3 text-hvm-text-sekundaer">
                                Sie können die zu Ihrem Konto gespeicherten Daten als Archivdatei exportieren. Enthalten sind die
                                ausgelesenen Inhaltsdaten und die erzeugten Abrechnungen, nicht Ihre Originalunterlagen, da diese
                                bereits gelöscht sind.
                            </p>
                        </x-hvm.card>

                        <x-hvm.card tone="canvas" class="min-w-0">
                            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="user" /></span>
                            <h3 class="mt-5 text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl">Kontolöschung</h3>
                            <p class="mt-3 text-hvm-text-sekundaer">
                                Sie können die Löschung Ihres Kontos veranlassen. Rechnungen, die aus steuer- und
                                handelsrechtlichen Gründen aufzubewahren sind, werden vom gelöschten Konto entkoppelt und nur im
                                erforderlichen Umfang weiter aufbewahrt.
                            </p>
                        </x-hvm.card>
                    </div>

                    <div class="mt-10 flex flex-wrap gap-3">
                        <x-hvm.button href="{{ route('site.faq') }}" variant="secondary" size="lg">Häufige Fragen</x-hvm.button>
                        <x-hvm.button href="{{ route('site.kontakt') }}" variant="ghost" size="lg">Frage zum Datenschutz stellen</x-hvm.button>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
