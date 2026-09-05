{{--
    Uploadzone eines Abrechnungslaufs.

    GESTALTUNGSGRUNDSAETZE (Masterprompt 9 Schritt 2, Abschnitt 18):
    - Eine einzige Zone nimmt alle Dokumentarten an. Der Nutzer muss nichts
      vorsortieren, das System klassifiziert selbst.
    - Die Kategorie ist ein optionaler Hinweis, niemals eine Pflichtangabe.
    - Status wird nie allein ueber Farbe kommuniziert. Jede Zeile traegt ein
      ausgeschriebenes Statuswort.
    - Der Loeschhinweis steht sichtbar im Uploaddialog, nicht im Kleingedruckten.

    DATENSCHUTZ: Angezeigt werden ausschliesslich neutrale Quellenbezeichnungen.
    Der Originaldateiname erscheint nur waehrend der Uebertragung im Browser des
    Nutzers und wird serverseitig nicht gespeichert.

    DESIGN: Die Dateiauswahl ist die Haupthandlung dieser Seite und traegt
    deshalb die Primaerfarbe; der Weg zur Analyse ist die Nebenhandlung.
--}}
@extends('layouts.portal')

@inject('wizardProgress', 'App\Application\Wizard\WizardProgress')

@section('titel', 'Unterlagen hochladen')

@section('content')
    <x-hvm.page-header
        :eyebrow="\App\Application\Wizard\WizardStep::UPLOAD->eyebrow()"
        title="Unterlagen hochladen"
        lead="Laden Sie alles hoch, was Sie haben. Die Reihenfolge ist gleichgültig, das System ordnet die Unterlagen selbst zu." />

    <div class="mt-8">
        @include('portal.wizard.partials.fortschritt', [
            'fortschritt' => $wizardProgress->bar($billingRun, \App\Application\Wizard\WizardStep::UPLOAD),
            'billingRun' => $billingRun,
            'wiedereinstieg' => null,
        ])
    </div>

    {{-- Uploadzone ---------------------------------------------------------- --}}

    <x-hvm.card :kennlinie="true" padding="none" class="mt-10 rounded-3xl">
        <div
            class="p-6 sm:p-8"
            data-upload-zone
            data-start-url="{{ url('/app/abrechnungen/'.$billingRun->getKey().'/uploads') }}"
            data-status-url="{{ url('/app/abrechnungen/'.$billingRun->getKey().'/uploads/status') }}"
            data-upload-base="{{ url('/app/uploads') }}"
            data-chunk-bytes="{{ $abschnittsgroesse }}"
            data-max-file-mb="{{ $maxDateiMb }}"
            data-csrf="{{ csrf_token() }}"
        >
            <div
                data-upload-dropzone
                class="rounded-2xl border-2 border-dashed border-hvm-hellgrau bg-hvm-canvas px-5 py-10 text-center transition-colors duration-150 sm:px-10 sm:py-12"
            >
                <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-hvm-orange-soft text-hvm-orange-dark" aria-hidden="true">
                    <x-hvm.icon name="upload" class="h-7 w-7" />
                </span>

                <p class="mt-5 text-lg font-semibold tracking-tight text-hvm-textschwarz sm:text-xl">
                    Dateien hierher ziehen
                </p>
                <p class="mx-auto mt-2 max-w-prose text-sm leading-relaxed text-hvm-text-sekundaer">
                    PDF, JPG, PNG, HEIC, CSV und ZIP. Bis {{ $maxDateiMb }} MB je Datei,
                    bis {{ $maxLaufMb }} MB je Abrechnungslauf. Excel-Tabellen (XLSX) werden derzeit nicht
                    ausgewertet, bitte speichern Sie sie als CSV oder PDF.
                </p>

                <div class="mt-6">
                    {{-- Das Label loest die Dateiauswahl aus und traegt das Erscheinungsbild des Primaerbuttons. --}}
                    <x-hvm.button as="label" for="upload-dateien" variant="primary">
                        <x-hvm.icon name="plus" class="h-4 w-4" />
                        Dateien auswählen
                    </x-hvm.button>
                    <input
                        id="upload-dateien"
                        data-upload-input
                        type="file"
                        multiple
                        class="sr-only"
                        accept=".pdf,.jpg,.jpeg,.png,.heic,.heif,.csv,.zip"
                    >
                </div>

                <div class="mx-auto mt-8 max-w-md text-left">
                    <label for="upload-kategorie" class="block text-sm font-semibold text-hvm-textschwarz">
                        Kategorie (optional)
                    </label>
                    <select
                        id="upload-kategorie"
                        data-upload-category
                        class="hvm-input mt-2"
                    >
                        <option value="">Automatisch erkennen</option>
                        @foreach ($kategorien as $kategorie)
                            <option value="{{ $kategorie->value }}">{{ $kategorie->label() }}</option>
                        @endforeach
                    </select>
                    <p class="mt-2 text-sm leading-relaxed text-hvm-text-sekundaer">
                        Eine Angabe ist nicht erforderlich. Sie hilft nur, wenn eine Unterlage
                        erfahrungsgemäß schwer einzuordnen ist.
                    </p>
                </div>
            </div>

            {{-- Fortschritt je Datei. Wird ausschliesslich im Browser gefuehrt. --}}
            <ul data-upload-progress class="mt-6 space-y-3" aria-live="polite"></ul>

            <noscript>
                <div class="mt-6">
                    <x-hvm.alert variant="warning" title="JavaScript ist erforderlich">
                        Der Upload überträgt große Dateien in kleinen Abschnitten und benötigt dafür
                        JavaScript. Bitte aktivieren Sie JavaScript für diese Seite.
                    </x-hvm.alert>
                </div>
            </noscript>
        </div>
    </x-hvm.card>

    {{-- Verbindlicher Loeschhinweis nach Abschnitt 6.4 und ehrlicher Hinweis
         zum Umfang der automatischen Auswertung (ARCHITECTURE 11.1) ------------ --}}

    <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-hvm.alert variant="info" label="Hinweis" title="Umgang mit Ihren Originaldateien" class="min-w-0">
            Ihre Originaldateien werden nur zur Auswertung kurzfristig verarbeitet und anschließend
            automatisch gelöscht. Bitte bewahren Sie Ihre Originalbelege selbst auf.
            <span class="mt-2 block">
                Spätestens {{ $aufbewahrungMinuten }} Minuten nach dem Hochladen wird die Originaldatei in
                jedem Fall gelöscht, auch wenn die Auswertung nicht abgeschlossen werden konnte. Für einen
                neuen Versuch laden Sie die Datei bitte erneut hoch.
            </span>
        </x-hvm.alert>

        <x-hvm.alert variant="info" label="Hinweis" title="Was automatisch ausgewertet wird" class="min-w-0">
            Ausgewertet werden Hausgeldabrechnung, Grundsteuerbescheid, Heizkostenabrechnung, Rechnungen und
            Gebührenbescheide. Mietverträge, Vorjahresabrechnungen, Mieter- und Einheitenlisten, Zahlungsübersichten
            und Zählerlisten werden abgelegt und Ihnen zur Sichtprüfung angezeigt; die Werte daraus, etwa
            Vorauszahlungen, Umlagevereinbarungen und Zählerstände, erfassen Sie in den folgenden Schritten selbst.
        </x-hvm.alert>
    </div>

    {{-- Statusliste je Dokument ---------------------------------------------- --}}

    <section class="mt-16" aria-labelledby="ueberschrift-verarbeitungsstand">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs font-semibold tracking-[0.12em] text-hvm-text-sekundaer uppercase">Ihre Unterlagen</p>
                <h2 id="ueberschrift-verarbeitungsstand" class="mt-1 text-2xl font-semibold tracking-tight text-hvm-textschwarz">Verarbeitungsstand</h2>
            </div>
        </div>

        <p class="mt-3 max-w-prose text-sm leading-relaxed text-hvm-text-sekundaer">
            Die Auswertung läuft im Hintergrund in festen Abständen. Hochgeladene Unterlagen werden spätestens nach
            {{ max(1, (int) config('smartabrechnen.scheduler_interval_minutes', 5)) }} Minuten
            verarbeitet. Den Stand sehen Sie nach dem Neuladen dieser Seite oder auf der Seite der Analyse.
        </p>

        <div class="mt-6">
            @include('portal.upload.partials.statusliste', ['dokumente' => $dokumente])
        </div>
    </section>

    <div class="mt-10 flex flex-wrap gap-3">
        <x-hvm.button href="{{ route('portal.pruefung.analyse', ['billingRun' => $billingRun->getKey()]) }}"
                      variant="secondary">
            Weiter zur Analyse
            <x-hvm.icon name="arrow-right" class="h-4 w-4" />
        </x-hvm.button>
    </div>
@endsection
