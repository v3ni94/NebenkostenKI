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
--}}
@extends('layouts.portal')

@section('titel', 'Unterlagen hochladen')

@section('content')
    <x-hvm.section-heading
        title="Unterlagen hochladen"
        lead="Laden Sie alles hoch, was Sie haben. Die Reihenfolge ist gleichgültig, das System ordnet die Unterlagen selbst zu." />

    {{-- Verbindlicher Loeschhinweis nach Abschnitt 6.4 ------------------------ --}}

    <x-hvm.alert variant="info" label="Hinweis" title="Umgang mit Ihren Originaldateien" class="mt-8">
        Ihre Originaldateien werden nur zur Auswertung kurzfristig verarbeitet und anschließend
        automatisch gelöscht. Bitte bewahren Sie Ihre Originalbelege selbst auf.
        <span class="mt-2 block">
            Spätestens {{ $aufbewahrungMinuten }} Minuten nach dem Hochladen wird die Originaldatei in
            jedem Fall gelöscht, auch wenn die Auswertung nicht abgeschlossen werden konnte. Für einen
            neuen Versuch laden Sie die Datei bitte erneut hoch.
        </span>
    </x-hvm.alert>

    {{-- Uploadzone ---------------------------------------------------------- --}}

    <x-hvm.card class="mt-8" accent>
        <div
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
                class="rounded-lg border-2 border-dashed border-hvm-mittelgrau bg-hvm-umrissgrau p-8 text-center"
            >
                <p class="text-lg font-semibold text-hvm-anthrazit">
                    Dateien hierher ziehen
                </p>
                <p class="mt-2 text-sm text-hvm-textschwarz">
                    PDF, JPG, PNG, HEIC, CSV, XLSX und ZIP. Bis {{ $maxDateiMb }} MB je Datei,
                    bis {{ $maxLaufMb }} MB je Abrechnungslauf.
                </p>

                <div class="mt-5">
                    <label
                        for="upload-dateien"
                        class="inline-flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-md border border-hvm-orange-dark bg-hvm-orange px-5 py-2.5 text-base font-semibold text-hvm-textschwarz hover:bg-hvm-orange-dark"
                    >
                        Dateien auswählen
                    </label>
                    <input
                        id="upload-dateien"
                        data-upload-input
                        type="file"
                        multiple
                        class="sr-only"
                        accept=".pdf,.jpg,.jpeg,.png,.heic,.heif,.csv,.xlsx,.zip"
                    >
                </div>

                <div class="mt-5 text-left sm:mx-auto sm:max-w-md">
                    <label for="upload-kategorie" class="block text-sm font-semibold text-hvm-anthrazit">
                        Kategorie (optional)
                    </label>
                    <select
                        id="upload-kategorie"
                        data-upload-category
                        class="mt-1 min-h-11 w-full rounded-md border border-hvm-mittelgrau bg-white px-3 py-2 text-base"
                    >
                        <option value="">Automatisch erkennen</option>
                        @foreach ($kategorien as $kategorie)
                            <option value="{{ $kategorie->value }}">{{ $kategorie->label() }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-sm text-hvm-anthrazit">
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

    {{-- Statusliste je Dokument ---------------------------------------------- --}}

    <h2 class="mt-12 text-xl font-bold text-hvm-anthrazit">Verarbeitungsstand</h2>

    @include('portal.upload.partials.statusliste', ['dokumente' => $dokumente])
@endsection
