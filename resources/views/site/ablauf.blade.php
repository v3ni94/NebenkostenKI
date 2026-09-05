@extends('layouts.site')

@section('meta_title', 'So funktioniert es')
@section('meta_description', 'Der geführte Ablauf von Smart Abrechnen in zwölf Schritten: hochladen, auswerten lassen, offene Punkte bestätigen, Vorschau prüfen, zahlen und die Final-PDFs erhalten.')

@php
    $ttlMinuten = (int) config('smartabrechnen.retention.temp_upload_ttl_minutes');
@endphp

@section('content')
    {{-- Seitenkopf Website (Designsystem 4.2). --}}
    <section class="bg-hvm-canvas">
        <div class="mx-auto max-w-7xl px-4 pt-16 pb-20 sm:px-6 lg:px-8 lg:pt-24 lg:pb-28">
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-12 lg:items-end">
                <div class="min-w-0 lg:col-span-7">
                    <x-hvm.badge variant="akzent" :icon="false">Ablauf</x-hvm.badge>
                    {{-- Weiche Trennstelle im Kompositum, damit die H1 bei 390 px sauber bricht. --}}
                    <h1 class="mt-6 text-4xl leading-[1.05] font-semibold tracking-tight text-hvm-textschwarz sm:text-5xl lg:text-6xl">
                        So entsteht Ihre Betriebskosten&shy;abrechnung
                    </h1>
                    <p class="mt-7 max-w-prose text-lg leading-relaxed text-hvm-text-sekundaer sm:text-xl">
                        Der Ablauf besteht aus zwölf Schritten in fünf Abschnitten. Jeder Schritt speichert automatisch. Sie
                        können jederzeit unterbrechen und später ohne Datenverlust weiterarbeiten. Ihr Überblick zeigt statt
                        technischer Meldungen eine klare Liste: erledigt, bitte prüfen, fehlt noch und blockiert die
                        Abrechnung.
                    </p>
                </div>

                {{-- Uebersicht der fuenf Abschnitte als Sprungliste. --}}
                <nav class="min-w-0 lg:col-span-4 lg:col-start-9" aria-label="Abschnitte des Ablaufs">
                    <x-hvm.card padding="none" class="rounded-3xl">
                        <p class="px-6 pt-5 text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">Fünf Abschnitte</p>
                        <ol class="mt-3 divide-y divide-hvm-linie">
                            @foreach ([
                                ['abschnitt-1', 'Vorbereiten und hochladen', '1 bis 2'],
                                ['abschnitt-2', 'Automatisch auswerten lassen', '3'],
                                ['abschnitt-3', 'Offene Punkte bestätigen', '4 bis 8'],
                                ['abschnitt-4', 'Prüfbericht und Vorschau', '9 bis 10'],
                                ['abschnitt-5', 'Zahlen und abschließen', '11 bis 12'],
                            ] as [$ziel, $titel, $schritte])
                                <li>
                                    <a href="#{{ $ziel }}" class="flex min-h-11 items-center gap-4 px-6 py-3 text-sm font-medium text-hvm-textschwarz no-underline transition-colors hover:bg-hvm-canvas">
                                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-hvm-orange text-xs font-bold text-hvm-textschwarz tabular" aria-hidden="true">{{ $loop->iteration }}</span>
                                        <span class="min-w-0 flex-1">{{ $titel }}</span>
                                        <span class="shrink-0 text-xs text-hvm-text-sekundaer tabular">Schritte {{ $schritte }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ol>
                    </x-hvm.card>
                </nav>
            </div>
        </div>
    </section>

    {{-- Abschnitt 1 --}}
    <section id="abschnitt-1" class="border-y border-hvm-linie bg-white">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-12">
                <div class="min-w-0 lg:col-span-4">
                    <x-hvm.section-heading
                        level="h2"
                        eyebrow="Abschnitt 1 von 5"
                        title="Vorbereiten und hochladen"
                        lead="Sie legen den Abrechnungszeitraum fest und geben alle Unterlagen in einem Schritt ab." />
                </div>

                <ol class="min-w-0 divide-y divide-hvm-linie lg:col-span-8">
                    <li class="py-7 first:pt-0 last:pb-0">
                        <x-hvm.step number="1" title="Konto und Abrechnungsjahr">
                            <p>
                                Sie hinterlegen Name, E-Mail-Adresse und Rechnungsanschrift und wählen den
                                Abrechnungszeitraum. Voreingestellt ist der 01.01. bis 31.12. des Vorjahres. Anschließend
                                entscheiden Sie sich für die Schnellabrechnung einer Eigentumswohnung oder für die
                                vollständige Objektabrechnung. Eine Empfehlung erhalten Sie nach dem Upload.
                            </p>
                        </x-hvm.step>
                    </li>
                    <li class="py-7 first:pt-0 last:pb-0">
                        <x-hvm.step number="2" title="Alles hochladen">
                            <p>
                                Eine zentrale Ablagefläche nimmt alle Dokumentarten gleichzeitig an, auch ungeordnet und
                                gemischt. Eine Kategorie können Sie angeben, müssen es aber nicht. Für jede Datei sehen Sie
                                den Upload und den Verarbeitungsstand.
                            </p>
                            <x-hvm.alert class="mt-5" variant="info" title="Umgang mit Ihren Dateien">
                                Ihre Originaldateien werden nur zur Auswertung kurzfristig verarbeitet und anschließend
                                automatisch gelöscht, spätestens nach {{ $ttlMinuten }} Minuten. Bitte bewahren Sie Ihre
                                Originalbelege selbst auf.
                            </x-hvm.alert>
                        </x-hvm.step>
                    </li>
                </ol>
            </div>
        </div>
    </section>

    {{-- Abschnitt 2 --}}
    <section id="abschnitt-2" class="bg-hvm-canvas">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-12">
                <div class="min-w-0 lg:col-span-4">
                    <x-hvm.section-heading
                        level="h2"
                        eyebrow="Abschnitt 2 von 5"
                        title="Automatisch auswerten lassen"
                        lead="Das Portal ordnet Ihre Unterlagen ein und liest die benötigten Werte aus." />
                </div>

                <ol class="min-w-0 divide-y divide-hvm-linie lg:col-span-8" start="3">
                    <li class="py-7 first:pt-0 last:pb-0">
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
            </div>
        </div>
    </section>

    {{-- Abschnitt 3 --}}
    <section id="abschnitt-3" class="border-y border-hvm-linie bg-white">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-12">
                <div class="min-w-0 lg:col-span-4">
                    <x-hvm.section-heading
                        level="h2"
                        eyebrow="Abschnitt 3 von 5"
                        title="Offene Punkte bestätigen"
                        lead="Sie prüfen nur, was unklar, widersprüchlich oder unvollständig ist." />
                </div>

                <ol class="min-w-0 divide-y divide-hvm-linie lg:col-span-8" start="4">
                    <li class="py-7 first:pt-0 last:pb-0">
                        <x-hvm.step number="4" title="Objekt, Vermieter und Einheiten">
                            <p>
                                Sie bestätigen die Objektanschrift, den Eigentümer oder Vermieter als Absender und die
                                Einheiten mit Bezeichnung, Lage, Wohnfläche, Miteigentumsanteil und individuellen Werten.
                                Leerstehende Einheiten werden ausdrücklich gekennzeichnet. Flächen- und Anteilssummen
                                werden auf Plausibilität geprüft.
                            </p>
                        </x-hvm.step>
                    </li>
                    <li class="py-7 first:pt-0 last:pb-0">
                        <x-hvm.step number="5" title="Mietverhältnisse und Zeitachse">
                            <p>
                                Sie bestätigen Mieter, Zustellanschrift, Einzug, Auszug, Mieterwechsel und Leerstand. Eine
                                barrierearme Zeitachse je Einheit macht Überschneidungen und Lücken sichtbar. Der
                                Abrechnungszeitraum muss lückenlos belegt oder ausdrücklich als Leerstand gekennzeichnet
                                sein.
                            </p>
                        </x-hvm.step>
                    </li>
                    <li class="py-7 first:pt-0 last:pb-0">
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
                    <li class="py-7 first:pt-0 last:pb-0">
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
                    <li class="py-7 first:pt-0 last:pb-0">
                        <x-hvm.step number="8" title="Verteilerschlüssel und Verbrauch">
                            <p>
                                Vorgeschlagen wird der von Ihnen im Vorjahreslauf bestätigte Schlüssel, sonst ein
                                fachlich naheliegender Standard mit Warnhinweis. Ob der Vorschlag der Regelung in Ihrem
                                Mietvertrag entspricht, prüfen und bestätigen Sie vor dem Speichern. Zur
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
            </div>
        </div>
    </section>

    {{-- Abschnitt 4 --}}
    <section id="abschnitt-4" class="bg-hvm-canvas">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-12">
                <div class="min-w-0 lg:col-span-4">
                    <x-hvm.section-heading
                        level="h2"
                        eyebrow="Abschnitt 4 von 5"
                        title="Prüfbericht und Vorschau"
                        lead="Vor der Vorschau läuft die vollständige Prüfung. Danach sehen Sie das Ergebnis, noch ohne Zahlung." />
                </div>

                <ol class="min-w-0 divide-y divide-hvm-linie lg:col-span-8" start="9">
                    <li class="py-7 first:pt-0 last:pb-0">
                        <x-hvm.step number="9" title="Prüfbericht">
                            <p>Die Ergebnisse sind in vier Gruppen sortiert:</p>
                            {{-- Statusgruppen: Symbol plus Text, nie nur Farbe (Designsystem 4.9). --}}
                            <ul class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <li class="flex min-w-0 flex-col gap-2 rounded-2xl border border-hvm-linie bg-white p-4">
                                    <x-hvm.badge variant="error" icon="alert" class="self-start">Blocker</x-hvm.badge>
                                    <span class="text-sm leading-relaxed text-hvm-textschwarz">Die Finalisierung ist nicht möglich.</span>
                                </li>
                                <li class="flex min-w-0 flex-col gap-2 rounded-2xl border border-hvm-linie bg-white p-4">
                                    <x-hvm.badge variant="warning" icon="warning" class="self-start">Warnung</x-hvm.badge>
                                    <span class="text-sm leading-relaxed text-hvm-textschwarz">Ihre ausdrückliche Entscheidung ist erforderlich.</span>
                                </li>
                                <li class="flex min-w-0 flex-col gap-2 rounded-2xl border border-hvm-linie bg-white p-4">
                                    <x-hvm.badge variant="info" icon="info" class="self-start">Hinweis</x-hvm.badge>
                                    <span class="text-sm leading-relaxed text-hvm-textschwarz">Plausibel, aber gut zu wissen.</span>
                                </li>
                                <li class="flex min-w-0 flex-col gap-2 rounded-2xl border border-hvm-linie bg-white p-4">
                                    <x-hvm.badge variant="success" class="self-start">Bestanden</x-hvm.badge>
                                    <span class="text-sm leading-relaxed text-hvm-textschwarz">Der Prüfschritt war erfolgreich.</span>
                                </li>
                            </ul>
                        </x-hvm.step>
                    </li>
                    <li class="py-7 first:pt-0 last:pb-0">
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
            </div>
        </div>
    </section>

    {{-- Abschnitt 5 --}}
    <section id="abschnitt-5" class="border-y border-hvm-linie bg-white">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <div class="grid grid-cols-1 gap-12 lg:grid-cols-12">
                <div class="min-w-0 lg:col-span-4">
                    <x-hvm.section-heading
                        level="h2"
                        eyebrow="Abschnitt 5 von 5"
                        title="Zahlen und abschließen"
                        lead="Erst die bestätigte Zahlung schaltet die Abrechnungen ohne Wasserzeichen frei." />
                </div>

                <ol class="min-w-0 divide-y divide-hvm-linie lg:col-span-8" start="11">
                    <li class="py-7 first:pt-0 last:pb-0">
                        <x-hvm.step number="11" title="Zahlung">
                            <p>
                                Der Endpreis wird vor dem Bezahlvorgang anhand der tatsächlich erzeugten Mieterabrechnungen
                                erneut berechnet. Die Zahlung läuft über die gesicherte Bezahlseite eines
                                Zahlungsdienstleisters. Freigeschaltet wird erst, wenn die Zahlung dort bestätigt ist. Die
                                Rückleitung in den Browser allein genügt nicht.
                            </p>
                        </x-hvm.step>
                    </li>
                    <li class="py-7 first:pt-0 last:pb-0">
                        <x-hvm.step number="12" title="Finalisierung">
                            <p>
                                Der bezahlte Berechnungsstand wird unveränderlich gesichert. Danach werden alle PDFs ohne
                                Wasserzeichen neu erzeugt. Sie erhalten:
                            </p>
                            <ul class="mt-4 space-y-3 text-hvm-textschwarz">
                                <li class="flex gap-3"><span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="check" /></span><span>eine Mieterabrechnung je Mietverhältnis als einzelne Datei</span></li>
                                <li class="flex gap-3"><span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="check" /></span><span>alle Abrechnungen zusammen als ZIP-Datei</span></li>
                                <li class="flex gap-3"><span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="check" /></span><span>die Eigentümerübersicht mit Leerstandsanteilen und ausgeschlossenen Kosten</span></li>
                                <li class="flex gap-3"><span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="check" /></span><span>die Rechnung der {{ config('smartabrechnen.operator.legal_name') }}</span></li>
                                <li class="flex gap-3"><span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="check" /></span><span>eine Bestätigungs-E-Mail mit einem gesicherten Download-Link</span></li>
                            </ul>
                            <p class="mt-4">
                                Eine finalisierte Abrechnung wird nie überschrieben. Eine Korrektur erzeugt eine neue
                                Version, die frühere Fassung bleibt als ersetzt erkennbar.
                            </p>
                        </x-hvm.step>
                    </li>
                </ol>
            </div>
        </div>
    </section>

    {{-- Verantwortung und Abschluss --}}
    <section class="bg-hvm-canvas">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <div class="mx-auto max-w-3xl">
                <x-hvm.alert variant="warning" title="Ihre Verantwortung bleibt">
                    <p>
                        Absender und inhaltlich verantwortlich für die Betriebskostenabrechnung ist der Vermieter. Smart
                        Abrechnen ist ein Software-Werkzeug und erbringt keine Rechtsberatung im Einzelfall. Bei streitigen
                        Fragen wenden Sie sich an einen Rechtsanwalt oder Steuerberater.
                    </p>
                </x-hvm.alert>

                <div class="mt-12 text-center">
                    <span class="mx-auto block h-1 w-12 rounded-full bg-hvm-orange" aria-hidden="true"></span>
                    <div class="mt-8 flex flex-wrap justify-center gap-3">
                        <x-hvm.button href="{{ url('/app') }}" variant="primary" size="lg">
                            Kostenlos starten
                            <x-hvm.icon name="arrow-right" class="h-5 w-5" />
                        </x-hvm.button>
                        <x-hvm.button href="{{ route('site.preise') }}" variant="secondary" size="lg">Preise ansehen</x-hvm.button>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
