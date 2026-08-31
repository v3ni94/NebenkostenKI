/*
 * Chunk-Upload der Anwendung.
 *
 * WARUM CHUNKS: Auf IONOS Webhosting sind post_max_size und
 * upload_max_filesize nicht verlaesslich hoch konfigurierbar. Die Datei wird
 * deshalb in kleinen Abschnitten uebertragen und serverseitig wieder
 * zusammengesetzt (Masterprompt 6.1).
 *
 * DATENSCHUTZ:
 * - Der Originaldateiname bleibt im Browser. Zum Server geht nur die Endung,
 *   weil sie fuer den Abgleich mit den Magic Bytes benoetigt wird.
 * - Es wird keine Vorschau der Datei erzeugt und kein Dateiinhalt in
 *   localStorage oder sessionStorage abgelegt.
 * - Es gibt keine Analysetracker und keine Fehlerweitergabe an Dritte.
 *
 * WIEDERAUFNAHME: Bricht die Verbindung ab, fragt der Browser den Zustand des
 * Uploads ab und uebertraegt nur die fehlenden Abschnitte erneut.
 */

const MAX_CHUNK_ATTEMPTS = 3;

/**
 * Nur die Endung, nie der vollstaendige Name.
 */
function dateiendung(name) {
    const position = name.lastIndexOf('.');

    return position === -1 ? '' : name.slice(position + 1).toLowerCase();
}

function megabyte(bytes) {
    return (bytes / (1024 * 1024)).toFixed(1).replace('.', ',');
}

/**
 * Eine Zeile im Fortschrittsbereich. Der angezeigte Dateiname stammt
 * ausschliesslich aus der lokalen Dateiauswahl.
 */
function zeileAnlegen(liste, datei) {
    const zeile = document.createElement('li');
    zeile.className = 'rounded-lg border border-hvm-hellgrau bg-white p-4';

    const kopf = document.createElement('div');
    kopf.className = 'flex flex-wrap items-baseline justify-between gap-2';

    const name = document.createElement('span');
    name.className = 'font-semibold text-hvm-textschwarz';
    name.textContent = datei.name;

    const groesse = document.createElement('span');
    groesse.className = 'text-sm text-hvm-anthrazit';
    groesse.textContent = megabyte(datei.size) + ' MB';

    kopf.append(name, groesse);

    const balken = document.createElement('div');
    balken.className = 'mt-3 h-2 w-full overflow-hidden rounded bg-hvm-umrissgrau';

    const fuellung = document.createElement('div');
    fuellung.className = 'h-2 w-0 bg-hvm-orange';
    balken.append(fuellung);

    const status = document.createElement('p');
    status.className = 'mt-2 text-sm text-hvm-textschwarz';
    status.textContent = 'Vorbereitung';

    zeile.append(kopf, balken, status);
    liste.append(zeile);

    return {
        setzeFortschritt(anteil) {
            fuellung.style.width = Math.round(anteil * 100) + '%';
        },
        setzeStatus(text, art) {
            status.textContent = text;
            status.className =
                'mt-2 text-sm ' +
                (art === 'fehler'
                    ? 'font-semibold text-status-error'
                    : art === 'fertig'
                      ? 'font-semibold text-status-success'
                      : 'text-hvm-textschwarz');
        },
    };
}

async function jsonAnfrage(url, optionen, csrf) {
    const antwort = await fetch(url, {
        ...optionen,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrf,
            ...(optionen.headers || {}),
        },
        credentials: 'same-origin',
    });

    let inhalt = null;

    try {
        inhalt = await antwort.json();
    } catch (fehler) {
        inhalt = null;
    }

    if (!antwort.ok) {
        const meldung =
            (inhalt && (inhalt.meldung || inhalt.message)) ||
            'Der Upload ist fehlgeschlagen. Bitte versuchen Sie es erneut.';

        throw new Error(meldung);
    }

    return inhalt;
}

/**
 * Uebertraegt genau einen Abschnitt, mit begrenzter Wiederholung.
 */
async function abschnittSenden(uploadBasis, uploadId, index, teil, csrf) {
    const formular = new FormData();
    formular.append('index', String(index));
    formular.append('abschnitt', teil, 'abschnitt.bin');

    let letzterFehler = null;

    for (let versuch = 1; versuch <= MAX_CHUNK_ATTEMPTS; versuch++) {
        try {
            return await jsonAnfrage(
                uploadBasis + '/' + uploadId + '/abschnitte',
                { method: 'POST', body: formular },
                csrf,
            );
        } catch (fehler) {
            letzterFehler = fehler;
            await new Promise((fertig) => setTimeout(fertig, versuch * 750));
        }
    }

    throw letzterFehler;
}

async function dateiHochladen(zone, datei, anzeige) {
    const csrf = zone.dataset.csrf;
    const startUrl = zone.dataset.startUrl;
    const uploadBasis = zone.dataset.uploadBase;
    const chunkBytes = Number.parseInt(zone.dataset.chunkBytes, 10) || 4 * 1024 * 1024;
    const maxFileMb = Number.parseInt(zone.dataset.maxFileMb, 10) || 25;
    const kategorie = zone.querySelector('[data-upload-category]');

    if (datei.size > maxFileMb * 1024 * 1024) {
        anzeige.setzeStatus(
            'Die Datei ist größer als ' + maxFileMb + ' MB und wurde nicht übertragen.',
            'fehler',
        );

        return;
    }

    const endung = dateiendung(datei.name);

    anzeige.setzeStatus('Upload wird vorbereitet');

    const start = await jsonAnfrage(
        startUrl,
        {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                dateiname: datei.name,
                groesse: datei.size,
                kategorie: kategorie && kategorie.value !== '' ? kategorie.value : null,
            }),
        },
        csrf,
    );

    const uploadId = start.upload_id;
    const abschnitte = start.abschnitte;

    // Wiederaufnahme: der Server nennt die noch fehlenden Abschnitte.
    let fehlende = [];

    try {
        const zustand = await jsonAnfrage(uploadBasis + '/' + uploadId, { method: 'GET' }, csrf);
        fehlende = zustand.fehlende_abschnitte || [];
    } catch (fehler) {
        fehlende = Array.from({ length: abschnitte }, (wert, index) => index);
    }

    let uebertragen = abschnitte - fehlende.length;

    for (const index of fehlende) {
        const beginn = index * chunkBytes;
        const teil = datei.slice(beginn, Math.min(beginn + chunkBytes, datei.size));

        await abschnittSenden(uploadBasis, uploadId, index, teil, csrf);

        uebertragen += 1;
        anzeige.setzeFortschritt(uebertragen / abschnitte);
        anzeige.setzeStatus('Übertragung: Abschnitt ' + uebertragen + ' von ' + abschnitte);
    }

    anzeige.setzeStatus('Prüfung läuft');

    await jsonAnfrage(
        uploadBasis + '/' + uploadId + '/abschluss',
        {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ erweiterung: endung }),
        },
        csrf,
    );

    anzeige.setzeFortschritt(1);
    anzeige.setzeStatus(
        'Übertragen. Die Auswertung läuft und wird in der Statusliste angezeigt.',
        'fertig',
    );
}

async function dateienVerarbeiten(zone, dateien) {
    const liste = zone.querySelector('[data-upload-progress]');

    if (!liste) {
        return;
    }

    // Bewusst nacheinander: ein Webhosting-Tarif vertraegt keine beliebige
    // Anzahl gleichzeitiger Uebertragungen.
    for (const datei of Array.from(dateien)) {
        const anzeige = zeileAnlegen(liste, datei);

        try {
            await dateiHochladen(zone, datei, anzeige);
        } catch (fehler) {
            anzeige.setzeStatus(
                fehler && fehler.message
                    ? fehler.message
                    : 'Der Upload ist fehlgeschlagen. Bitte versuchen Sie es erneut.',
                'fehler',
            );
        }
    }
}

export function initUploadZone(zone) {
    const eingabe = zone.querySelector('[data-upload-input]');
    const flaeche = zone.querySelector('[data-upload-dropzone]');

    if (eingabe) {
        eingabe.addEventListener('change', () => {
            if (eingabe.files && eingabe.files.length > 0) {
                dateienVerarbeiten(zone, eingabe.files);
                eingabe.value = '';
            }
        });
    }

    if (!flaeche) {
        return;
    }

    ['dragenter', 'dragover'].forEach((ereignis) => {
        flaeche.addEventListener(ereignis, (event) => {
            event.preventDefault();
            flaeche.classList.add('border-hvm-orange', 'bg-hvm-orange-soft');
        });
    });

    ['dragleave', 'drop'].forEach((ereignis) => {
        flaeche.addEventListener(ereignis, (event) => {
            event.preventDefault();
            flaeche.classList.remove('border-hvm-orange', 'bg-hvm-orange-soft');
        });
    });

    flaeche.addEventListener('drop', (event) => {
        if (event.dataTransfer && event.dataTransfer.files.length > 0) {
            dateienVerarbeiten(zone, event.dataTransfer.files);
        }
    });
}

export function initUploadZones() {
    document.querySelectorAll('[data-upload-zone]').forEach((zone) => initUploadZone(zone));
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initUploadZones);
    } else {
        initUploadZones();
    }
}
