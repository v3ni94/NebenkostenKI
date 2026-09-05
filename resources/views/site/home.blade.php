@extends('layouts.site')

@section('meta_title', 'Betriebskostenabrechnung online erstellen')
@section('meta_description', 'Smart Abrechnen erstellt aus Hausgeldabrechnung, Grundsteuerbescheid, Heizkostenabrechnung und Belegen eine strukturierte Betriebskostenabrechnung. Konto und Entwürfe kostenlos, Zahlung erst nach der Vorschau.')

@php
    $preisBruttoCent = (int) config('smartabrechnen.pricing.per_statement_gross_cent');
    $grundpreisCent = (int) config('smartabrechnen.pricing.base_gross_cent');
    $preisBrutto = number_format($preisBruttoCent / 100, 2, ',', '.').' EUR';
    $grundpreis = number_format($grundpreisCent / 100, 2, ',', '.').' EUR';
@endphp

@section('content')
    {{-- Hero: links Text, rechts stilisierte Abrechnung als CSS/HTML-Mockup --}}
    <section class="bg-hvm-canvas">
        <div class="mx-auto grid max-w-7xl items-center gap-14 px-4 pt-16 pb-20 sm:px-6 lg:grid-cols-12 lg:gap-10 lg:px-8 lg:pt-24 lg:pb-28">
            <div class="lg:col-span-7">
                <x-hvm.badge variant="akzent">Die digitalste Hausverwaltung</x-hvm.badge>

                <h1 class="mt-6 text-4xl leading-[1.05] font-semibold tracking-tight text-hvm-textschwarz sm:text-5xl lg:text-6xl">
                    Ihre Betriebskosten&shy;abrechnung entsteht aus den Unterlagen, die Sie bereits haben
                </h1>

                <p class="mt-7 max-w-prose text-lg leading-relaxed text-hvm-text-sekundaer sm:text-xl">
                    Laden Sie Hausgeldabrechnung, Grundsteuerbescheid, Heizkostenabrechnung und Belege ungeordnet
                    hoch. Smart Abrechnen erkennt die Unterlagen, liest die Kostenwerte aus und stellt Ihnen die
                    offenen Punkte zur Prüfung vor. Vorauszahlungen, Mietzeiten und Umlagevereinbarungen erfassen Sie
                    selbst in wenigen Schritten. Die Beträge werden ausschließlich rechnerisch ermittelt, nicht
                    geschätzt.
                </p>

                <div class="mt-10 flex flex-wrap gap-3">
                    <x-hvm.button href="{{ url('/app') }}" variant="primary" size="lg">
                        Kostenlos starten
                        <x-hvm.icon name="arrow-right" class="h-5 w-5" />
                    </x-hvm.button>
                    <x-hvm.button href="{{ route('site.ablauf') }}" variant="secondary" size="lg">So funktioniert es</x-hvm.button>
                </div>

                <ul class="mt-12 grid gap-x-8 gap-y-6 border-t border-hvm-linie pt-8 sm:grid-cols-3">
                    <li class="flex gap-3">
                        <span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="check-circle" /></span>
                        <span class="text-sm leading-relaxed text-hvm-text-sekundaer">
                            <span class="block font-semibold text-hvm-textschwarz">Konto kostenlos</span>
                            Registrierung und Entwürfe kosten nichts.
                        </span>
                    </li>
                    <li class="flex gap-3">
                        <span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="euro" /></span>
                        <span class="text-sm leading-relaxed text-hvm-text-sekundaer">
                            <span class="block font-semibold text-hvm-textschwarz">Zahlung nach Vorschau</span>
                            Sie sehen das Ergebnis, bevor Sie zahlen.
                        </span>
                    </li>
                    <li class="flex gap-3">
                        <span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="shield" /></span>
                        <span class="text-sm leading-relaxed text-hvm-text-sekundaer">
                            <span class="block font-semibold text-hvm-textschwarz">Dateien werden gelöscht</span>
                            Originale bleiben nicht im Portal.
                        </span>
                    </li>
                </ul>
            </div>

            {{--
                Stilisierte Abrechnung als reines CSS/HTML-Mockup (keine Bilder,
                CSP). Die Zahlen sind Platzhalter einer Vorschau und werden
                durch aria-hidden von der Sprachausgabe ausgenommen.
            --}}
            <div class="lg:col-span-5" aria-hidden="true">
                <div class="relative mx-auto max-w-md lg:max-w-none">
                    <div class="absolute -top-3 right-3 z-10 rotate-6 rounded-full border-2 border-hvm-orange-dark bg-hvm-canvas px-4 py-1.5 text-xs font-bold tracking-[0.2em] text-hvm-orange-dark uppercase sm:px-5 sm:text-sm">
                        Vorschau
                    </div>

                    <div class="overflow-hidden rounded-3xl border border-hvm-linie bg-white shadow-hairline">
                        <div class="hvm-kennlinie"></div>

                        <div class="p-6 sm:p-8">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">Betriebskostenabrechnung</p>
                                    <p class="mt-1 text-lg font-semibold tracking-tight text-hvm-textschwarz">Abrechnungsjahr 2025</p>
                                    <p class="mt-1 text-xs text-hvm-text-sekundaer">01.01.2025 bis 31.12.2025</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-xs text-hvm-text-sekundaer">Wohnung 3, 2. OG</p>
                                    <p class="text-xs text-hvm-text-sekundaer">Fläche 68,40 m²</p>
                                </div>
                            </div>

                            <div class="mt-8 space-y-5">
                                <div>
                                    <div class="flex items-baseline justify-between gap-4 text-sm">
                                        <span class="font-medium text-hvm-textschwarz">Grundsteuer</span>
                                        <span class="tabular text-hvm-textschwarz">312,40 EUR</span>
                                    </div>
                                    <div class="mt-2 h-2 w-full rounded-full bg-hvm-canvas-deep">
                                        <div class="h-2 w-[38%] rounded-full bg-hvm-anthrazit"></div>
                                    </div>
                                </div>
                                <div>
                                    <div class="flex items-baseline justify-between gap-4 text-sm">
                                        <span class="font-medium text-hvm-textschwarz">Heizung und Warmwasser</span>
                                        <span class="tabular text-hvm-textschwarz">704,15 EUR</span>
                                    </div>
                                    <div class="mt-2 h-2 w-full rounded-full bg-hvm-canvas-deep">
                                        <div class="h-2 w-[86%] rounded-full bg-hvm-orange"></div>
                                    </div>
                                </div>
                                <div>
                                    <div class="flex items-baseline justify-between gap-4 text-sm">
                                        <span class="font-medium text-hvm-textschwarz">Hausgeld, umlagefähig</span>
                                        <span class="tabular text-hvm-textschwarz">489,60 EUR</span>
                                    </div>
                                    <div class="mt-2 h-2 w-full rounded-full bg-hvm-canvas-deep">
                                        <div class="h-2 w-[60%] rounded-full bg-hvm-mittelgrau"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-8 rounded-2xl bg-hvm-canvas p-5">
                                <div class="flex items-baseline justify-between gap-4 text-sm text-hvm-text-sekundaer">
                                    <span>Gesamtkosten</span>
                                    <span class="tabular">1.506,15 EUR</span>
                                </div>
                                <div class="mt-2 flex items-baseline justify-between gap-4 text-sm text-hvm-text-sekundaer">
                                    <span>Vorauszahlungen (Ist)</span>
                                    <span class="tabular">1.380,00 EUR</span>
                                </div>
                                <div class="mt-4 flex items-baseline justify-between gap-4 border-t border-hvm-linie pt-4">
                                    <span class="text-sm font-semibold text-hvm-textschwarz">Nachzahlung</span>
                                    <span class="text-2xl font-semibold tracking-tight text-hvm-textschwarz tabular">126,15 EUR</span>
                                </div>
                            </div>

                            <div class="mt-6 flex flex-wrap items-center gap-2">
                                <x-hvm.badge variant="success">Erledigt</x-hvm.badge>
                                <span class="text-xs text-hvm-text-sekundaer">Quelle: Hausgeldabrechnung, Seite 2</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Zielgruppe --}}
    <section class="border-y border-hvm-linie bg-white">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <div class="grid gap-12 lg:grid-cols-12">
                <div class="lg:col-span-5">
                    <x-hvm.section-heading
                        eyebrow="Zielgruppe"
                        title="Für wen Smart Abrechnen gedacht ist" />
                    <p class="mt-6 max-w-prose text-base leading-relaxed text-hvm-text-sekundaer">
                        Der aktuelle Umfang ist auf die Wohnraummiete in Deutschland ausgelegt. Gewerbliche
                        Mietverhältnisse werden nicht stillschweigend nach Wohnraummietrecht abgerechnet.
                    </p>
                </div>
                <ul class="grid gap-4 sm:grid-cols-2 lg:col-span-7">
                    <li class="flex gap-4 rounded-2xl border border-hvm-linie bg-hvm-canvas p-5">
                        <span class="text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="key" class="h-6 w-6" /></span>
                        <span class="text-base leading-relaxed text-hvm-textschwarz">Private Vermieter einer einzelnen Eigentumswohnung</span>
                    </li>
                    <li class="flex gap-4 rounded-2xl border border-hvm-linie bg-hvm-canvas p-5">
                        <span class="text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="house" class="h-6 w-6" /></span>
                        <span class="text-base leading-relaxed text-hvm-textschwarz">Vermieter kleiner und mittlerer Mehrfamilienhäuser</span>
                    </li>
                    <li class="flex gap-4 rounded-2xl border border-hvm-linie bg-hvm-canvas p-5">
                        <span class="text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="layers" class="h-6 w-6" /></span>
                        <span class="text-base leading-relaxed text-hvm-textschwarz">Bestandshalter mit mehreren Objekten</span>
                    </li>
                    <li class="flex gap-4 rounded-2xl border border-hvm-linie bg-hvm-canvas p-5">
                        <span class="text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="user" class="h-6 w-6" /></span>
                        <span class="text-base leading-relaxed text-hvm-textschwarz">Hausverwaltungen, die im Namen eines Eigentümers abrechnen</span>
                    </li>
                </ul>
            </div>
        </div>
    </section>

    {{-- Zwei Abrechnungswege --}}
    <section class="bg-hvm-canvas">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <x-hvm.section-heading
                eyebrow="Zwei Wege"
                title="Schnellabrechnung oder vollständige Objektabrechnung"
                lead="Nach dem Upload schlägt Ihnen das Portal den passenden Weg vor. Sie können jederzeit wechseln, ohne bereits ausgelesene Inhaltsdaten zu verlieren." />

            <div class="mt-14 grid gap-6 lg:grid-cols-2">
                <x-hvm.card :accent="true" eyebrow="Weg 1" title="Schnellabrechnung für die Eigentumswohnung" class="rounded-3xl p-7 sm:p-9">
                    <p class="text-hvm-text-sekundaer">
                        Für Vermieter einer einzelnen Einheit in einer Wohnungseigentümergemeinschaft. Grundlage sind Ihre
                        Hausgeldabrechnung und der Grundsteuerbescheid.
                    </p>

                    <p class="mt-6 text-sm font-semibold tracking-[0.08em] text-hvm-textschwarz uppercase">Das Portal übernimmt dabei:</p>
                    <ul class="mt-3 space-y-3">
                        <li class="flex gap-3"><span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="check" /></span><span>die auf Ihre Einheit entfallenden umlagefähigen Kosten aus der Hausgeldabrechnung</span></li>
                        <li class="flex gap-3"><span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="check" /></span><span>die Grundsteuer, sofern sie nicht bereits in der Hausgeldabrechnung enthalten ist</span></li>
                        <li class="flex gap-3"><span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="check" /></span><span>eine separate Heizkostenabrechnung, soweit vorhanden</span></li>
                        <li class="flex gap-3"><span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="check" /></span><span>die taggenaue Sollsumme der Vorauszahlungen aus den von Ihnen erfassten Monatsbeträgen</span></li>
                    </ul>

                    <p class="mt-6 border-t border-hvm-linie pt-5 text-sm leading-relaxed text-hvm-text-sekundaer">
                        Nicht umlagefähige Positionen wie Verwaltervergütung, Instandhaltung, Reparaturen und die Zuführung
                        zur Erhaltungsrücklage werden voreingestellt ausgeschlossen und gesondert ausgewiesen.
                    </p>
                </x-hvm.card>

                <x-hvm.card :accent="true" eyebrow="Weg 2" title="Vollständige Objektabrechnung für Mehrfamilienhäuser" class="rounded-3xl p-7 sm:p-9">
                    <p class="text-hvm-text-sekundaer">
                        Für ein ganzes Objekt mit mehreren Einheiten. Sie laden alle Rechnungen, Gebührenbescheide und
                        Heizkostenabrechnungen hoch und erfassen Einheiten, Mietzeiten, Vorauszahlungen und Zählerstände
                        in den geführten Schritten.
                    </p>

                    <p class="mt-6 text-sm font-semibold tracking-[0.08em] text-hvm-textschwarz uppercase">Das Portal übernimmt dabei:</p>
                    <ul class="mt-3 space-y-3">
                        <li class="flex gap-3"><span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="check" /></span><span>die Bildung der Gesamtkosten je Kostenart aus Ihren Belegen</span></li>
                        <li class="flex gap-3"><span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="check" /></span><span>die Verteilung auf alle Mietverhältnisse nach den bestätigten Schlüsseln</span></li>
                        <li class="flex gap-3"><span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="check" /></span><span>taggenaue Zeitanteile bei Mieterwechsel, Einzug, Auszug und Leerstand</span></li>
                        <li class="flex gap-3"><span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="check" /></span><span>den Abgleich von Belegsummen und Prüfsummen</span></li>
                    </ul>

                    <p class="mt-6 border-t border-hvm-linie pt-5 text-sm leading-relaxed text-hvm-text-sekundaer">
                        Leerstandsanteile bleiben beim Eigentümer und werden in der Eigentümerübersicht getrennt
                        dargestellt. Für Zeiten ohne Mietverhältnis liegen keine Personenangaben vor; der Schlüssel
                        Personentage ist deshalb bei Leerstand nicht verwendbar, das Portal weist darauf hin und
                        schätzt nichts.
                    </p>
                </x-hvm.card>
            </div>
        </div>
    </section>

    {{-- Fuenf Schritte --}}
    <section class="border-y border-hvm-linie bg-white">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <div class="grid gap-12 lg:grid-cols-12">
                <div class="lg:col-span-4">
                    <x-hvm.section-heading
                        eyebrow="Ablauf"
                        title="In fünf Schritten zur fertigen Abrechnung"
                        lead="Jeder Schritt speichert automatisch. Sie können jederzeit unterbrechen und später ohne Datenverlust weiterarbeiten." />

                    <div class="mt-8">
                        <x-hvm.button href="{{ route('site.ablauf') }}" variant="secondary">
                            Alle zwölf Schritte im Detail
                            <x-hvm.icon name="arrow-right" class="h-4 w-4" />
                        </x-hvm.button>
                    </div>
                </div>

                <ol class="divide-y divide-hvm-linie lg:col-span-8">
                    <li class="py-7 first:pt-0 last:pb-0">
                        <x-hvm.step number="1" title="Unterlagen hochladen">
                            Eine Ablagefläche nimmt alle Dokumentarten gleichzeitig und ungeordnet an. Eine Vorsortierung
                            ist möglich, aber nicht erforderlich.
                        </x-hvm.step>
                    </li>
                    <li class="py-7 first:pt-0 last:pb-0">
                        <x-hvm.step number="2" title="Automatische Auswertung">
                            Das Portal erkennt die Dokumentarten, liest die benötigten Werte aus und ordnet Kostenarten,
                            Zeiträume, Einheiten und Schlüssel zu.
                        </x-hvm.step>
                    </li>
                    <li class="py-7 first:pt-0 last:pb-0">
                        <x-hvm.step number="3" title="Offene Punkte prüfen">
                            Sie bestätigen nur, was unklar, widersprüchlich oder unvollständig ist. Fehlende Werte werden
                            nicht geschätzt, sondern als Prüfaufgabe angezeigt.
                        </x-hvm.step>
                    </li>
                    <li class="py-7 first:pt-0 last:pb-0">
                        <x-hvm.step number="4" title="Vorschau ansehen">
                            Alle Mieterabrechnungen und die Eigentümerübersicht werden als Vorschau erzeugt. Jede
                            Vorschauseite trägt ein Wasserzeichen.
                        </x-hvm.step>
                    </li>
                    <li class="py-7 first:pt-0 last:pb-0">
                        <x-hvm.step number="5" title="Nach Zahlung die Final-PDFs erhalten">
                            Erst nach bestätigter Zahlung werden die PDFs ohne Wasserzeichen neu erzeugt. Sie erhalten die
                            Einzeldateien, eine ZIP-Datei, die Eigentümerübersicht und die Rechnung der
                            {{ config('smartabrechnen.operator.legal_name') }}.
                        </x-hvm.step>
                    </li>
                </ol>
            </div>
        </div>
    </section>

    {{-- Was Sie brauchen --}}
    <section class="bg-hvm-canvas">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <x-hvm.section-heading
                eyebrow="Vorbereitung"
                title="Was Sie brauchen"
                lead="Je vollständiger Ihre Unterlagen sind, desto weniger müssen Sie manuell nachtragen. Fehlt eine Angabe, benennt das Portal sie konkret." />

            <div class="mt-14 grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                <x-hvm.card>
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="document" /></span>
                    <h3 class="mt-5 text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl">Belege des Abrechnungsjahres</h3>
                    <p class="mt-3 text-hvm-text-sekundaer">
                        Rechnungen, Gebührenbescheide und Abrechnungen der Versorger sowie die Hausgeldabrechnung und der
                        Grundsteuerbescheid, soweit vorhanden.
                    </p>
                </x-hvm.card>

                <x-hvm.card>
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="key" /></span>
                    <h3 class="mt-5 text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl">Mietvertrag</h3>
                    <p class="mt-3 text-hvm-text-sekundaer">
                        Halten Sie ihn bereit: Die vereinbarten Betriebskosten, den Umlageschlüssel und die monatlichen
                        Vorauszahlungen tragen Sie aus dem Vertrag in die geführten Schritte ein. Hochgeladene Mietverträge
                        werden abgelegt und Ihnen zur Sichtprüfung angezeigt, die Werte daraus werden nicht automatisch
                        übernommen.
                    </p>
                </x-hvm.card>

                <x-hvm.card>
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="calendar" /></span>
                    <h3 class="mt-5 text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl">Vorjahresabrechnung</h3>
                    <p class="mt-3 text-hvm-text-sekundaer">
                        Sie hilft Ihnen beim Abgleich der Kostenarten. Hochgeladen wird sie abgelegt und angezeigt;
                        Vorjahreswerte werden nie als neue Kosten übernommen.
                    </p>
                </x-hvm.card>

                <x-hvm.card>
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="sparkle" /></span>
                    <h3 class="mt-5 text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl">Heizkostenabrechnung</h3>
                    <p class="mt-3 text-hvm-text-sekundaer">
                        Liegt eine externe Heizkostenabrechnung vor, werden deren Einzelbeträge übernommen und gegen die
                        Gesamtsumme geprüft, damit keine Position doppelt ansetzt.
                    </p>
                </x-hvm.card>

                <x-hvm.card>
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="euro" /></span>
                    <h3 class="mt-5 text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl">Nachweis der Vorauszahlungen</h3>
                    <p class="mt-3 text-hvm-text-sekundaer">
                        Kontoauszug oder Zahlungsübersicht für die tatsächlich geleisteten Vorauszahlungen. Den Ist-Betrag je
                        Mietverhältnis tragen Sie selbst ein; abgezogen werden die Ist-Zahlungen, Sollwerte dienen der
                        Kontrolle.
                    </p>
                </x-hvm.card>

                <x-hvm.card>
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="house" /></span>
                    <h3 class="mt-5 text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl">Angaben zu Einheiten und Mietzeiten</h3>
                    <p class="mt-3 text-hvm-text-sekundaer">
                        Wohnflächen, Miteigentumsanteile, Einzug, Auszug, Leerstand und Personenzahlen erfassen Sie in den
                        Stammdaten. Das Portal prüft die Angaben auf Lücken, Überschneidungen und Summen.
                    </p>
                </x-hvm.card>
            </div>
        </div>
    </section>

    {{-- Datenschutz-Kernversprechen als dunkle Flaeche --}}
    <section class="bg-hvm-graphit text-hvm-hellgrau">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <div class="grid gap-12 lg:grid-cols-12">
                <div class="lg:col-span-5">
                    <p class="flex items-center gap-3 text-xs font-semibold tracking-[0.12em] text-hvm-hellgrau uppercase">
                        <span class="inline-block h-px w-8 bg-hvm-orange" aria-hidden="true"></span>
                        Datenschutz
                    </p>
                    <h2 class="mt-4 text-3xl font-semibold tracking-tight text-white sm:text-4xl">
                        Ihre Originaldateien bleiben nicht im Portal
                    </h2>
                    <p class="mt-5 max-w-prose text-base leading-relaxed text-hvm-hellgrau sm:text-lg">
                        Zur Abrechnung sind nur die ausgelesenen Inhaltsdaten nötig. Alles andere wird nach der Auswertung nicht weiter aufbewahrt.
                    </p>

                    <div class="mt-8">
                        <x-hvm.button href="{{ route('site.datenschutz-konzept') }}" variant="inverse">
                            Löschkonzept im Detail
                        </x-hvm.button>
                    </div>
                </div>

                <div class="lg:col-span-7">
                    <ol class="divide-y divide-hvm-graphit-soft rounded-3xl border border-hvm-graphit-soft bg-hvm-graphit-soft/40">
                        <li class="flex gap-5 p-6 sm:p-7">
                            <span class="mt-1 text-hvm-orange" aria-hidden="true"><x-hvm.icon name="clock" class="h-6 w-6" /></span>
                            <div>
                                <h3 class="text-lg font-semibold tracking-tight text-white">Kurzfristige Verarbeitung</h3>
                                <p class="mt-2 text-base leading-relaxed text-hvm-hellgrau">
                                    Originaldateien werden nur für die technisch notwendige Dauer von Upload, Prüfung und
                                    Auswertung in einem verschlüsselten temporären Bereich verarbeitet.
                                </p>
                            </div>
                        </li>
                        <li class="flex gap-5 p-6 sm:p-7">
                            <span class="mt-1 text-hvm-orange" aria-hidden="true"><x-hvm.icon name="trash" class="h-6 w-6" /></span>
                            <div>
                                <h3 class="text-lg font-semibold tracking-tight text-white">Automatische Löschung</h3>
                                <p class="mt-2 text-base leading-relaxed text-hvm-hellgrau">
                                    Unmittelbar nach der Auswertung oder nach einem endgültigen Verarbeitungsfehler werden
                                    die Originaldateien automatisch gelöscht, spätestens nach Ablauf der kurzen
                                    Aufbewahrungsfrist.
                                </p>
                            </div>
                        </li>
                        <li class="flex gap-5 p-6 sm:p-7">
                            <span class="mt-1 text-hvm-orange" aria-hidden="true"><x-hvm.icon name="list" class="h-6 w-6" /></span>
                            <div>
                                <h3 class="text-lg font-semibold tracking-tight text-white">Dauerhaft nur Inhaltsdaten</h3>
                                <p class="mt-2 text-base leading-relaxed text-hvm-hellgrau">
                                    Dauerhaft bleiben ausschließlich die ausgelesenen Inhaltsdaten, also die für die
                                    Abrechnung erforderlichen Werte mit Angabe der Quelle und der Seite.
                                </p>
                            </div>
                        </li>
                    </ol>

                    <div class="mt-6">
                        <x-hvm.alert variant="info" title="Ihre Aufbewahrung">
                            Bitte bewahren Sie Ihre Originalbelege selbst auf und halten Sie sie für eine mögliche
                            Belegeinsicht Ihrer Mieter bereit. Das Portal ist kein Belegarchiv.
                        </x-hvm.alert>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Preise --}}
    <section class="bg-hvm-canvas">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <x-hvm.section-heading
                eyebrow="Preis"
                title="Bezahlt wird je erzeugter Mieterabrechnung"
                lead="Abrechnungseinheit für den Preis ist die erzeugte Mieterabrechnung, nicht die Wohnung. Bei einem Mieterwechsel entstehen für eine Einheit mehrere Mieterabrechnungen." />

            <div class="mt-14 grid gap-6 lg:grid-cols-12">
                <div class="lg:col-span-5">
                    <div class="rounded-3xl border border-hvm-linie bg-white p-7 sm:p-9">
                        <p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">
                            Je Mieterabrechnung
                        </p>
                        <p class="mt-4 text-5xl font-semibold tracking-tight text-hvm-textschwarz tabular sm:text-6xl">{{ $preisBrutto }}</p>
                        <p class="mt-3 text-sm leading-relaxed text-hvm-text-sekundaer">
                            Bruttopreis inklusive Umsatzsteuer. Netto und Umsatzsteuer werden im Bezahlvorgang und auf
                            der Rechnung getrennt ausgewiesen.
                        </p>

                        <ul class="mt-8 space-y-3 border-t border-hvm-linie pt-6 text-base text-hvm-textschwarz">
                            <li class="flex gap-3"><span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="check" /></span><span>Konto und Entwürfe kostenlos</span></li>
                            <li class="flex gap-3"><span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="check" /></span><span>Zahlung erst nach Prüfung der Vorschau</span></li>
                            <li class="flex gap-3"><span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="check" /></span><span>Kein Abonnement und keine Grundgebühr</span></li>
                            @if ($grundpreisCent === 0)
                                <li class="flex gap-3"><span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="check" /></span><span>Grundpreis je Abrechnungslauf: {{ $grundpreis }}</span></li>
                            @else
                                <li class="flex gap-3"><span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="check" /></span><span>Grundpreis je Abrechnungslauf: {{ $grundpreis }} brutto</span></li>
                            @endif
                            <li class="flex gap-3"><span class="mt-0.5 text-hvm-orange-dark" aria-hidden="true"><x-hvm.icon name="check" /></span><span>Erinnerungen für Folgejahre kostenlos</span></li>
                        </ul>

                        <div class="mt-8 flex flex-wrap gap-3">
                            <x-hvm.button href="{{ url('/app') }}" variant="primary">Kostenlos starten</x-hvm.button>
                            <x-hvm.button href="{{ route('site.preise') }}" variant="ghost">Rechenbeispiel ansehen</x-hvm.button>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-7">
                    <x-hvm.card title="Was das in der Praxis bedeutet" class="h-full rounded-3xl p-7 sm:p-9">
                        <p class="text-hvm-text-sekundaer">
                            Sie sehen die Anzahl der Mieterabrechnungen und den genauen Endpreis, bevor Sie zahlen. Vor
                            der Vorschau erhalten Sie eine unverbindliche Schätzung, vor dem Bezahlvorgang wird der
                            Preis anhand der tatsächlich erzeugten Abrechnungen erneut berechnet.
                        </p>
                        <p class="mt-4 text-hvm-text-sekundaer">
                            Die Rechnung stellt die {{ config('smartabrechnen.operator.legal_name') }}. Ein
                            Abonnement entsteht nicht, auch nicht durch die Erinnerungsfunktion für Folgejahre.
                        </p>
                        <p class="mt-6">
                            <a href="{{ route('site.preise') }}" class="inline-flex items-center gap-2 font-semibold text-hvm-textschwarz underline decoration-hvm-orange decoration-2 underline-offset-4">
                                Vollständige Preisdarstellung mit Rechenbeispiel
                                <x-hvm.icon name="arrow-right" class="h-4 w-4" />
                            </a>
                        </p>
                    </x-hvm.card>
                </div>
            </div>
        </div>
    </section>

    {{-- Abgrenzung --}}
    <section class="border-y border-hvm-linie bg-white">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <x-hvm.section-heading
                eyebrow="Verantwortung"
                title="Was Smart Abrechnen leistet und was nicht" />

            <div class="mt-12 grid gap-6 lg:grid-cols-2">
                <x-hvm.card title="Das leistet das Portal" tone="canvas">
                    <ul class="space-y-3">
                        <li class="flex gap-3"><span class="mt-0.5 text-status-success" aria-hidden="true"><x-hvm.icon name="check" /></span><span>Erkennung und Sortierung Ihrer Unterlagen</span></li>
                        <li class="flex gap-3"><span class="mt-0.5 text-status-success" aria-hidden="true"><x-hvm.icon name="check" /></span><span>Auslesen der benötigten Werte mit Angabe von Quelle, Seite und Konfidenz</span></li>
                        <li class="flex gap-3"><span class="mt-0.5 text-status-success" aria-hidden="true"><x-hvm.icon name="check" /></span><span>fachlich strukturierte Verteilung und Berechnung durch festen Programmcode</span></li>
                        <li class="flex gap-3"><span class="mt-0.5 text-status-success" aria-hidden="true"><x-hvm.icon name="check" /></span><span>technische Plausibilitätsprüfungen gegen Belege, Prüfsummen und das Vorjahr</span></li>
                        <li class="flex gap-3"><span class="mt-0.5 text-status-success" aria-hidden="true"><x-hvm.icon name="check" /></span><span>Erstellung von Mieterabrechnungen, Eigentümerübersicht und Anlagen</span></li>
                    </ul>
                </x-hvm.card>

                <x-hvm.card title="Das bleibt Ihre Aufgabe" tone="canvas">
                    <ul class="space-y-3">
                        <li class="flex gap-3"><span class="mt-0.5 text-hvm-text-sekundaer" aria-hidden="true"><x-hvm.icon name="user" /></span><span>Sie prüfen und bestätigen alle Werte, Schlüssel und Ergebnisse.</span></li>
                        <li class="flex gap-3"><span class="mt-0.5 text-hvm-text-sekundaer" aria-hidden="true"><x-hvm.icon name="user" /></span><span>Sie sind Absender der Abrechnung und inhaltlich verantwortlich.</span></li>
                        <li class="flex gap-3"><span class="mt-0.5 text-hvm-text-sekundaer" aria-hidden="true"><x-hvm.icon name="user" /></span><span>Sie versenden die Abrechnung an Ihre Mieter.</span></li>
                        <li class="flex gap-3"><span class="mt-0.5 text-hvm-text-sekundaer" aria-hidden="true"><x-hvm.icon name="user" /></span><span>Sie bewahren Ihre Originalbelege auf und gewähren die Belegeinsicht.</span></li>
                    </ul>
                </x-hvm.card>
            </div>

            <div class="mt-8">
                <x-hvm.alert variant="warning" title="Keine Rechtsberatung">
                    <p>
                        Smart Abrechnen ist ein Software-Werkzeug und erbringt keine Rechtsberatung im Einzelfall. Hinweise
                        zur Umlagefähigkeit sind fachliche Vorschläge und keine rechtliche Freigabe. Bei streitigen oder
                        haftungsrelevanten Fragen wenden Sie sich an einen Rechtsanwalt oder Steuerberater.
                    </p>
                </x-hvm.alert>
            </div>
        </div>
    </section>

    {{-- Abschluss-CTA --}}
    <section class="bg-hvm-canvas">
        <div class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8 lg:py-28">
            <div class="mx-auto max-w-3xl text-center">
                <span class="mx-auto block h-1 w-12 rounded-full bg-hvm-orange" aria-hidden="true"></span>
                <h2 class="mt-8 text-3xl font-semibold tracking-tight text-hvm-textschwarz sm:text-4xl lg:text-5xl">
                    Legen Sie Ihre Abrechnung an, prüfen Sie die Vorschau, entscheiden Sie danach
                </h2>
                <p class="mx-auto mt-6 max-w-prose text-lg leading-relaxed text-hvm-text-sekundaer">
                    Das Konto und alle Entwürfe sind kostenlos. Ein Betrag entsteht erst, wenn Sie die Vorschau geprüft
                    haben und die Final-PDFs möchten.
                </p>
                <div class="mt-10 flex flex-wrap justify-center gap-3">
                    <x-hvm.button href="{{ url('/app') }}" variant="primary" size="lg">Kostenlos starten</x-hvm.button>
                    <x-hvm.button href="{{ route('site.faq') }}" variant="secondary" size="lg">Häufige Fragen</x-hvm.button>
                </div>
            </div>
        </div>
    </section>
@endsection
