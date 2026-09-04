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
    {{-- Hero --}}
    <section class="border-b border-hvm-umrissgrau bg-hvm-umrissgrau">
        <div class="mx-auto grid max-w-6xl gap-10 px-4 py-14 sm:px-6 sm:py-20 lg:grid-cols-12">
            <div class="lg:col-span-7">
                <x-hvm.badge variant="akzent">Die digitalste Hausverwaltung</x-hvm.badge>

                <h1 class="mt-5 text-3xl leading-tight font-bold text-hvm-anthrazit sm:text-4xl lg:text-5xl">
                    Ihre Betriebskostenabrechnung entsteht aus den Unterlagen, die Sie bereits haben
                </h1>

                <p class="mt-5 max-w-2xl text-lg leading-relaxed text-hvm-textschwarz">
                    Laden Sie Hausgeldabrechnung, Grundsteuerbescheid, Heizkostenabrechnung und Belege ungeordnet
                    hoch. Smart Abrechnen erkennt die Unterlagen, liest die Kostenwerte aus und stellt Ihnen die
                    offenen Punkte zur Prüfung vor. Vorauszahlungen, Mietzeiten und Umlagevereinbarungen erfassen Sie
                    selbst in wenigen Schritten. Die Beträge werden ausschließlich rechnerisch ermittelt, nicht
                    geschätzt.
                </p>

                <div class="mt-8 flex flex-wrap gap-3">
                    <x-hvm.button href="{{ url('/app') }}" variant="primary" size="lg">Kostenlos starten</x-hvm.button>
                    <x-hvm.button href="{{ route('site.ablauf') }}" variant="secondary" size="lg">So funktioniert es</x-hvm.button>
                </div>

                <ul class="mt-8 grid gap-3 sm:grid-cols-3">
                    <li class="rounded-lg border border-hvm-hellgrau bg-white px-4 py-3 text-sm text-hvm-textschwarz">
                        <span class="block font-semibold text-hvm-anthrazit">Konto kostenlos</span>
                        Registrierung und Entwürfe kosten nichts.
                    </li>
                    <li class="rounded-lg border border-hvm-hellgrau bg-white px-4 py-3 text-sm text-hvm-textschwarz">
                        <span class="block font-semibold text-hvm-anthrazit">Zahlung nach Vorschau</span>
                        Sie sehen das Ergebnis, bevor Sie zahlen.
                    </li>
                    <li class="rounded-lg border border-hvm-hellgrau bg-white px-4 py-3 text-sm text-hvm-textschwarz">
                        <span class="block font-semibold text-hvm-anthrazit">Dateien werden gelöscht</span>
                        Originale bleiben nicht im Portal.
                    </li>
                </ul>
            </div>

            <div class="lg:col-span-5">
                <x-hvm.card :accent="true" title="Für wen Smart Abrechnen gedacht ist">
                    <ul class="space-y-3">
                        <li class="flex gap-3">
                            <span class="mt-2 h-2 w-2 shrink-0 rounded-full bg-hvm-orange" aria-hidden="true"></span>
                            <span>Private Vermieter einer einzelnen Eigentumswohnung</span>
                        </li>
                        <li class="flex gap-3">
                            <span class="mt-2 h-2 w-2 shrink-0 rounded-full bg-hvm-orange" aria-hidden="true"></span>
                            <span>Vermieter kleiner und mittlerer Mehrfamilienhäuser</span>
                        </li>
                        <li class="flex gap-3">
                            <span class="mt-2 h-2 w-2 shrink-0 rounded-full bg-hvm-orange" aria-hidden="true"></span>
                            <span>Bestandshalter mit mehreren Objekten</span>
                        </li>
                        <li class="flex gap-3">
                            <span class="mt-2 h-2 w-2 shrink-0 rounded-full bg-hvm-orange" aria-hidden="true"></span>
                            <span>Hausverwaltungen, die im Namen eines Eigentümers abrechnen</span>
                        </li>
                    </ul>

                    <p class="mt-5 text-sm text-hvm-textschwarz">
                        Der aktuelle Umfang ist auf die Wohnraummiete in Deutschland ausgelegt. Gewerbliche
                        Mietverhältnisse werden nicht stillschweigend nach Wohnraummietrecht abgerechnet.
                    </p>
                </x-hvm.card>
            </div>
        </div>
    </section>

    {{-- Zwei Abrechnungswege --}}
    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6 sm:py-20">
        <x-hvm.section-heading
            eyebrow="Zwei Wege"
            title="Schnellabrechnung oder vollständige Objektabrechnung"
            lead="Nach dem Upload schlägt Ihnen das Portal den passenden Weg vor. Sie können jederzeit wechseln, ohne bereits ausgelesene Inhaltsdaten zu verlieren." />

        <div class="mt-10 grid gap-6 lg:grid-cols-2">
            <x-hvm.card :accent="true" title="Schnellabrechnung für die Eigentumswohnung">
                <p>
                    Für Vermieter einer einzelnen Einheit in einer Wohnungseigentümergemeinschaft. Grundlage sind Ihre
                    Hausgeldabrechnung und der Grundsteuerbescheid.
                </p>

                <p class="mt-4 font-semibold text-hvm-anthrazit">Das Portal übernimmt dabei:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li>die auf Ihre Einheit entfallenden umlagefähigen Kosten aus der Hausgeldabrechnung</li>
                    <li>die Grundsteuer, sofern sie nicht bereits in der Hausgeldabrechnung enthalten ist</li>
                    <li>eine separate Heizkostenabrechnung, soweit vorhanden</li>
                    <li>die taggenaue Sollsumme der Vorauszahlungen aus den von Ihnen erfassten Monatsbeträgen</li>
                </ul>

                <p class="mt-4">
                    Nicht umlagefähige Positionen wie Verwaltervergütung, Instandhaltung, Reparaturen und die Zuführung
                    zur Erhaltungsrücklage werden voreingestellt ausgeschlossen und gesondert ausgewiesen.
                </p>
            </x-hvm.card>

            <x-hvm.card :accent="true" title="Vollständige Objektabrechnung für Mehrfamilienhäuser">
                <p>
                    Für ein ganzes Objekt mit mehreren Einheiten. Sie laden alle Rechnungen, Gebührenbescheide und
                    Heizkostenabrechnungen hoch und erfassen Einheiten, Mietzeiten, Vorauszahlungen und Zählerstände
                    in den geführten Schritten.
                </p>

                <p class="mt-4 font-semibold text-hvm-anthrazit">Das Portal übernimmt dabei:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li>die Bildung der Gesamtkosten je Kostenart aus Ihren Belegen</li>
                    <li>die Verteilung auf alle Mietverhältnisse nach den bestätigten Schlüsseln</li>
                    <li>taggenaue Zeitanteile bei Mieterwechsel, Einzug, Auszug und Leerstand</li>
                    <li>den Abgleich von Belegsummen und Prüfsummen</li>
                </ul>

                <p class="mt-4">
                    Leerstandsanteile bleiben beim Eigentümer und werden in der Eigentümerübersicht getrennt
                    dargestellt. Für Zeiten ohne Mietverhältnis liegen keine Personenangaben vor; der Schlüssel
                    Personentage ist deshalb bei Leerstand nicht verwendbar, das Portal weist darauf hin und
                    schätzt nichts.
                </p>
            </x-hvm.card>
        </div>
    </section>

    {{-- Fuenf Schritte --}}
    <section class="border-y border-hvm-umrissgrau bg-hvm-umrissgrau">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6 sm:py-20">
            <x-hvm.section-heading
                eyebrow="Ablauf"
                title="In fünf Schritten zur fertigen Abrechnung"
                lead="Jeder Schritt speichert automatisch. Sie können jederzeit unterbrechen und später ohne Datenverlust weiterarbeiten." />

            <ol class="mt-10 grid gap-8 md:grid-cols-2">
                <li>
                    <x-hvm.step number="1" title="Unterlagen hochladen">
                        Eine Ablagefläche nimmt alle Dokumentarten gleichzeitig und ungeordnet an. Eine Vorsortierung
                        ist möglich, aber nicht erforderlich.
                    </x-hvm.step>
                </li>
                <li>
                    <x-hvm.step number="2" title="Automatische Auswertung">
                        Das Portal erkennt die Dokumentarten, liest die benötigten Werte aus und ordnet Kostenarten,
                        Zeiträume, Einheiten und Schlüssel zu.
                    </x-hvm.step>
                </li>
                <li>
                    <x-hvm.step number="3" title="Offene Punkte prüfen">
                        Sie bestätigen nur, was unklar, widersprüchlich oder unvollständig ist. Fehlende Werte werden
                        nicht geschätzt, sondern als Prüfaufgabe angezeigt.
                    </x-hvm.step>
                </li>
                <li>
                    <x-hvm.step number="4" title="Vorschau ansehen">
                        Alle Mieterabrechnungen und die Eigentümerübersicht werden als Vorschau erzeugt. Jede
                        Vorschauseite trägt ein Wasserzeichen.
                    </x-hvm.step>
                </li>
                <li class="md:col-span-2">
                    <x-hvm.step number="5" title="Nach Zahlung die Final-PDFs erhalten">
                        Erst nach bestätigter Zahlung werden die PDFs ohne Wasserzeichen neu erzeugt. Sie erhalten die
                        Einzeldateien, eine ZIP-Datei, die Eigentümerübersicht und die Rechnung der
                        {{ config('smartabrechnen.operator.legal_name') }}.
                    </x-hvm.step>
                </li>
            </ol>

            <div class="mt-10">
                <x-hvm.button href="{{ route('site.ablauf') }}" variant="secondary">Alle zwölf Schritte im Detail</x-hvm.button>
            </div>
        </div>
    </section>

    {{-- Was Sie brauchen --}}
    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6 sm:py-20">
        <x-hvm.section-heading
            eyebrow="Vorbereitung"
            title="Was Sie brauchen"
            lead="Je vollständiger Ihre Unterlagen sind, desto weniger müssen Sie manuell nachtragen. Fehlt eine Angabe, benennt das Portal sie konkret." />

        <div class="mt-10 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            <x-hvm.card title="Belege des Abrechnungsjahres">
                Rechnungen, Gebührenbescheide und Abrechnungen der Versorger sowie die Hausgeldabrechnung und der
                Grundsteuerbescheid, soweit vorhanden.
            </x-hvm.card>

            <x-hvm.card title="Mietvertrag">
                Halten Sie ihn bereit: Die vereinbarten Betriebskosten, den Umlageschlüssel und die monatlichen
                Vorauszahlungen tragen Sie aus dem Vertrag in die geführten Schritte ein. Hochgeladene Mietverträge
                werden abgelegt und Ihnen zur Sichtprüfung angezeigt, die Werte daraus werden nicht automatisch
                übernommen.
            </x-hvm.card>

            <x-hvm.card title="Vorjahresabrechnung">
                Sie hilft Ihnen beim Abgleich der Kostenarten. Hochgeladen wird sie abgelegt und angezeigt;
                Vorjahreswerte werden nie als neue Kosten übernommen.
            </x-hvm.card>

            <x-hvm.card title="Heizkostenabrechnung">
                Liegt eine externe Heizkostenabrechnung vor, werden deren Einzelbeträge übernommen und gegen die
                Gesamtsumme geprüft, damit keine Position doppelt ansetzt.
            </x-hvm.card>

            <x-hvm.card title="Nachweis der Vorauszahlungen">
                Kontoauszug oder Zahlungsübersicht für die tatsächlich geleisteten Vorauszahlungen. Den Ist-Betrag je
                Mietverhältnis tragen Sie selbst ein; abgezogen werden die Ist-Zahlungen, Sollwerte dienen der
                Kontrolle.
            </x-hvm.card>

            <x-hvm.card title="Angaben zu Einheiten und Mietzeiten">
                Wohnflächen, Miteigentumsanteile, Einzug, Auszug, Leerstand und Personenzahlen erfassen Sie in den
                Stammdaten. Das Portal prüft die Angaben auf Lücken, Überschneidungen und Summen.
            </x-hvm.card>
        </div>
    </section>

    {{-- Datenschutz-Kernversprechen --}}
    <section class="border-y border-hvm-umrissgrau bg-white">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6 sm:py-20">
            <div class="grid gap-10 lg:grid-cols-12">
                <div class="lg:col-span-5">
                    <x-hvm.section-heading
                        eyebrow="Datenschutz"
                        title="Ihre Originaldateien bleiben nicht im Portal"
                        lead="Zur Abrechnung sind nur die ausgelesenen Inhaltsdaten nötig. Alles andere wird nach der Auswertung nicht weiter aufbewahrt." />

                    <div class="mt-8">
                        <x-hvm.button href="{{ route('site.datenschutz-konzept') }}" variant="secondary">
                            Löschkonzept im Detail
                        </x-hvm.button>
                    </div>
                </div>

                <div class="lg:col-span-7">
                    <ul class="space-y-4">
                        <li>
                            <x-hvm.card title="Kurzfristige Verarbeitung">
                                Originaldateien werden nur für die technisch notwendige Dauer von Upload, Prüfung und
                                Auswertung in einem verschlüsselten temporären Bereich verarbeitet.
                            </x-hvm.card>
                        </li>
                        <li>
                            <x-hvm.card title="Automatische Löschung">
                                Unmittelbar nach der Auswertung oder nach einem endgültigen Verarbeitungsfehler werden
                                die Originaldateien automatisch gelöscht, spätestens nach Ablauf der kurzen
                                Aufbewahrungsfrist.
                            </x-hvm.card>
                        </li>
                        <li>
                            <x-hvm.card title="Dauerhaft nur Inhaltsdaten">
                                Dauerhaft bleiben ausschließlich die ausgelesenen Inhaltsdaten, also die für die
                                Abrechnung erforderlichen Werte mit Angabe der Quelle und der Seite.
                            </x-hvm.card>
                        </li>
                        <li>
                            <x-hvm.alert variant="info" title="Ihre Aufbewahrung">
                                Bitte bewahren Sie Ihre Originalbelege selbst auf und halten Sie sie für eine mögliche
                                Belegeinsicht Ihrer Mieter bereit. Das Portal ist kein Belegarchiv.
                            </x-hvm.alert>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- Preise --}}
    <section class="border-b border-hvm-umrissgrau bg-hvm-umrissgrau">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6 sm:py-20">
            <x-hvm.section-heading
                eyebrow="Preis"
                title="Bezahlt wird je erzeugter Mieterabrechnung"
                lead="Abrechnungseinheit für den Preis ist die erzeugte Mieterabrechnung, nicht die Wohnung. Bei einem Mieterwechsel entstehen für eine Einheit mehrere Mieterabrechnungen." />

            <div class="mt-10 grid gap-6 lg:grid-cols-12">
                <div class="lg:col-span-5">
                    <div class="rounded-lg border border-hvm-hellgrau bg-white p-6">
                        <p class="text-sm font-semibold tracking-wide text-hvm-textschwarz uppercase">
                            Je Mieterabrechnung
                        </p>
                        <p class="mt-3 text-4xl font-bold text-hvm-anthrazit">{{ $preisBrutto }}</p>
                        <p class="mt-2 text-sm text-hvm-textschwarz">
                            Bruttopreis inklusive Umsatzsteuer. Netto und Umsatzsteuer werden im Bezahlvorgang und auf
                            der Rechnung getrennt ausgewiesen.
                        </p>

                        <ul class="mt-6 space-y-2 text-base text-hvm-textschwarz">
                            <li>Konto und Entwürfe kostenlos</li>
                            <li>Zahlung erst nach Prüfung der Vorschau</li>
                            <li>Kein Abonnement und keine Grundgebühr</li>
                            @if ($grundpreisCent === 0)
                                <li>Grundpreis je Abrechnungslauf: {{ $grundpreis }}</li>
                            @else
                                <li>Grundpreis je Abrechnungslauf: {{ $grundpreis }} brutto</li>
                            @endif
                            <li>Erinnerungen für Folgejahre kostenlos</li>
                        </ul>

                        <div class="mt-6 flex flex-wrap gap-3">
                            <x-hvm.button href="{{ url('/app') }}" variant="primary">Kostenlos starten</x-hvm.button>
                            <x-hvm.button href="{{ route('site.preise') }}" variant="ghost">Rechenbeispiel ansehen</x-hvm.button>
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-7">
                    <x-hvm.card title="Was das in der Praxis bedeutet">
                        <p>
                            Sie sehen die Anzahl der Mieterabrechnungen und den genauen Endpreis, bevor Sie zahlen. Vor
                            der Vorschau erhalten Sie eine unverbindliche Schätzung, vor dem Bezahlvorgang wird der
                            Preis anhand der tatsächlich erzeugten Abrechnungen erneut berechnet.
                        </p>
                        <p class="mt-4">
                            Die Rechnung stellt die {{ config('smartabrechnen.operator.legal_name') }}. Ein
                            Abonnement entsteht nicht, auch nicht durch die Erinnerungsfunktion für Folgejahre.
                        </p>
                        <p class="mt-4">
                            <a href="{{ route('site.preise') }}" class="font-medium underline underline-offset-2">
                                Vollständige Preisdarstellung mit Rechenbeispiel
                            </a>
                        </p>
                    </x-hvm.card>
                </div>
            </div>
        </div>
    </section>

    {{-- Abgrenzung --}}
    <section class="mx-auto max-w-6xl px-4 py-14 sm:px-6 sm:py-20">
        <x-hvm.section-heading
            eyebrow="Verantwortung"
            title="Was Smart Abrechnen leistet und was nicht" />

        <div class="mt-8 grid gap-6 lg:grid-cols-2">
            <x-hvm.card title="Das leistet das Portal">
                <ul class="list-disc space-y-1 pl-5">
                    <li>Erkennung und Sortierung Ihrer Unterlagen</li>
                    <li>Auslesen der benötigten Werte mit Angabe von Quelle, Seite und Konfidenz</li>
                    <li>fachlich strukturierte Verteilung und Berechnung durch festen Programmcode</li>
                    <li>technische Plausibilitätsprüfungen gegen Belege, Prüfsummen und das Vorjahr</li>
                    <li>Erstellung von Mieterabrechnungen, Eigentümerübersicht und Anlagen</li>
                </ul>
            </x-hvm.card>

            <x-hvm.card title="Das bleibt Ihre Aufgabe">
                <ul class="list-disc space-y-1 pl-5">
                    <li>Sie prüfen und bestätigen alle Werte, Schlüssel und Ergebnisse.</li>
                    <li>Sie sind Absender der Abrechnung und inhaltlich verantwortlich.</li>
                    <li>Sie versenden die Abrechnung an Ihre Mieter.</li>
                    <li>Sie bewahren Ihre Originalbelege auf und gewähren die Belegeinsicht.</li>
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
    </section>

    {{-- Abschluss-CTA --}}
    <section class="border-t border-hvm-umrissgrau bg-white">
        <div class="mx-auto max-w-4xl px-4 py-14 text-center sm:px-6 sm:py-20">
            <h2 class="text-2xl font-bold text-hvm-anthrazit sm:text-3xl">
                Legen Sie Ihre Abrechnung an, prüfen Sie die Vorschau, entscheiden Sie danach
            </h2>
            <p class="mt-4 text-lg text-hvm-textschwarz">
                Das Konto und alle Entwürfe sind kostenlos. Ein Betrag entsteht erst, wenn Sie die Vorschau geprüft
                haben und die Final-PDFs möchten.
            </p>
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <x-hvm.button href="{{ url('/app') }}" variant="primary" size="lg">Kostenlos starten</x-hvm.button>
                <x-hvm.button href="{{ route('site.faq') }}" variant="secondary" size="lg">Häufige Fragen</x-hvm.button>
            </div>
        </div>
    </section>
@endsection
