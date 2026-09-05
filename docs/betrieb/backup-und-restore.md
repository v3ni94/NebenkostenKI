# Backup und Restore

**Anwendung:** Smart Abrechnen (Nebenkostenabrechnungsportal)
**Betreiber:** Hausverwaltung Müller GmbH
**Stand:** 01.09.2026
**Grundlage:** Masterprompt Abschnitt 19 und 21, ARCHITECTURE.md Abschnitt 5 und 7

Diese Anleitung ist verbindlich. Sie beschreibt, was gesichert wird, was
ausdrücklich nicht gesichert wird, wie die Sicherung verschlüsselt und
aufbewahrt wird und wie der Restore nachgewiesen wird. Die Angaben zu
Pfaden, Nutzern und Zielsystemen sind im IONOS-Konto zu ermitteln und nicht zu
raten.

---

## 1. Grundsatz

Ein Backup ist der einzige Weg, auf dem eine bereits gelöschte Originaldatei
wieder auftauchen könnte. Der Ausschluss der Originaldaten aus jedem Backup ist
deshalb Bestandteil des Löschkonzepts und keine Betriebsempfehlung.

Es wird ausdrücklich **nicht** behauptet, eine Datei ließe sich auf gemeinsam
genutztem oder SSD-basiertem Storage forensisch überschreiben. Verbindlich sind:

1. logische Löschung,
2. Ausschluss aus allen Backups,
3. kurze Aufbewahrung im Kurzzeitbereich, standardmäßig höchstens 120 Minuten,
4. dokumentierter Löschstatus je Vorgang.

---

## 2. Was gesichert wird

| Gegenstand | Inhalt | Rhythmus |
| --- | --- | --- |
| MariaDB-Dump | vollständige Anwendungsdatenbank | täglich |
| Ergebnisartefakte | erzeugte Abrechnungs-PDFs, Eigentümerübersichten, Anlagen nach Paragraf 35a EStG, ZIP-Pakete, Rechnungen der Hausverwaltung Müller GmbH, Datenexporte | täglich |
| Konfiguration | Releasemetadaten, Cron- und Webserverkonfiguration ohne Zugangsdaten | bei Änderung |

Die Ergebnisartefakte liegen ausschließlich in den Artefaktverzeichnissen der
Ablage. Sie sind vom System erzeugt und enthalten keine Originalbelege.

---

## 3. Verbindliche Ausschlussliste

Die folgenden Pfade und Datenarten dürfen in **keinem** Backup liegen, weder im
Datenbankdump noch in einem Dateiarchiv, weder verschlüsselt noch
unverschlüsselt:

1. `storage/app/temporary-uploads` und jeder abgeleitete Kurzzeitpfad,
   also der Quarantänebereich mit den Originaluploads,
2. Seitenbilder und Vorschaubilder der Quelldokumente,
3. vollständige OCR-Dateien und vollständige PDF-Text-Layer,
4. KI-Zwischendaten, also rohe Prompts, rohe Antworten und
   zwischengespeicherte Providerdateien,
5. Queue-Payloads und Framework-Caches unter `storage/framework/cache`,
   `storage/framework/sessions` und `storage/framework/views`.

Die Liste ist in `App\Application\Privacy\BackupExclusionPolicy` als Code
hinterlegt, damit sie maschinell geprüft werden kann. Eine Ausschlussliste, die
nur in einer Anleitung steht, ist nicht überprüfbar.

---

## 4. Datenbankbackup, verschlüsselt, mit Retention

Der Cronjob läuft im IONOS-Konto. Der PHP-, `mysqldump`- und
Verzeichnispfad sind dort zu ermitteln. Die Zugangsdaten stehen in einer
Optionsdatei mit Rechten `600`, nicht in der Kommandozeile, damit sie nicht in
der Prozessliste erscheinen.

`~/.my.cnf.backup` (Rechte 600):

```ini
[client]
user=DB_BENUTZER
password=DB_PASSWORT
host=DB_HOST
```

Skript `~/bin/backup-datenbank.sh`:

```bash
#!/bin/bash
set -euo pipefail

ZIEL=~/backups/datenbank
AUFBEWAHRUNG_TAGE=30
STAND=$(date +%Y-%m-%d-%H%M)
DATENBANK=smartabrechnen

mkdir -p "$ZIEL"

# --single-transaction sichert konsistent ohne Tabellensperre (InnoDB).
# --no-tablespaces vermeidet ein sonst nötiges PROCESS-Recht.
# Die Ausgabe wird direkt verschlüsselt, es entsteht kein unverschlüsselter Dump.
mysqldump --defaults-extra-file=~/.my.cnf.backup \
    --single-transaction \
    --quick \
    --no-tablespaces \
    --routines \
    --events \
    --default-character-set=utf8mb4 \
    "$DATENBANK" \
  | gzip -9 \
  | gpg --batch --yes --encrypt --recipient backup@muellerhv.de \
        --output "$ZIEL/$DATENBANK-$STAND.sql.gz.gpg"

chmod 600 "$ZIEL/$DATENBANK-$STAND.sql.gz.gpg"

# Retention: ältere Sicherungen entfernen.
find "$ZIEL" -name "$DATENBANK-*.sql.gz.gpg" -type f -mtime +$AUFBEWAHRUNG_TAGE -delete

echo "Datenbankbackup erstellt: $DATENBANK-$STAND.sql.gz.gpg"
```

Cron-Eintrag im IONOS-Control-Center, täglich um 02:10 Uhr:

```
10 2 * * * /bin/bash /kunden/homes/BENUTZER/bin/backup-datenbank.sh >> /kunden/homes/BENUTZER/logs/backup-datenbank.log 2>&1
```

Verschlüsselung: asymmetrisch mit GnuPG. Der öffentliche Schlüssel liegt auf
dem Server, der private Schlüssel ausschließlich außerhalb des Servers. Ein
kompromittierter Webspace erlaubt damit kein Entschlüsseln älterer Sicherungen.
Der private Schlüssel und seine Passphrase werden getrennt vom Backupmedium
aufbewahrt.

Retention: 30 Tage rollierend, zusätzlich eine Monatssicherung mit einer
Aufbewahrung von zwölf Monaten an einem getrennten Ablageort.

---

## 5. Backup der Ergebnisartefakte

```bash
#!/bin/bash
set -euo pipefail

QUELLE=~/artefakte
ZIEL=~/backups/artefakte
MANIFEST=~/backups/manifest/artefakte-$(date +%Y-%m-%d-%H%M).txt
AUFBEWAHRUNG_TAGE=30
STAND=$(date +%Y-%m-%d-%H%M)

mkdir -p "$ZIEL" "$(dirname "$MANIFEST")"

# Ausschlussliste. Sie ist verbindlich und wird anschließend maschinell geprüft.
tar --create --gzip \
    --exclude='temporary-uploads' \
    --exclude='temporary_uploads' \
    --exclude='seitenbilder' \
    --exclude='page-images' \
    --exclude='thumbnails' \
    --exclude='ocr' \
    --exclude='textlayer' \
    --exclude='ki-zwischendaten' \
    --exclude='ai-payloads' \
    --exclude='ai-raw' \
    --exclude='provider-files' \
    --exclude='queue-payloads' \
    --file - \
    --directory "$QUELLE" . \
  | gpg --batch --yes --encrypt --recipient backup@muellerhv.de \
        --output "$ZIEL/artefakte-$STAND.tar.gz.gpg"

# Manifest aus derselben Auswahl erzeugen und maschinell prüfen.
tar --list --exclude='temporary-uploads' --exclude='temporary_uploads' \
    --exclude='seitenbilder' --exclude='page-images' --exclude='thumbnails' \
    --exclude='ocr' --exclude='textlayer' --exclude='ki-zwischendaten' \
    --exclude='ai-payloads' --exclude='ai-raw' --exclude='provider-files' \
    --exclude='queue-payloads' \
    --directory "$QUELLE" . > "$MANIFEST"

php artisan smartabrechnen:audit-backup-manifest "$MANIFEST" --regeln

find "$ZIEL" -name 'artefakte-*.tar.gz.gpg' -type f -mtime +$AUFBEWAHRUNG_TAGE -delete
```

`set -euo pipefail` sorgt dafür, dass das Skript abbricht, wenn die
Manifestprüfung mit Fehlercode endet. Ein nicht konformes Archiv wird nicht
weiterverwendet, sondern verworfen und nach Korrektur der Ausschlussliste neu
erstellt.

---

## 6. Automatisierte Prüfung der Ausschlussliste

```
php artisan smartabrechnen:audit-backup-manifest /pfad/manifest.txt
```

Das Manifest ist eine Textdatei mit einem Pfad je Zeile. Leerzeilen und Zeilen,
die mit `#` beginnen, werden übersprungen. Der Befehl endet mit Fehlercode `1`,
sobald ein Pfad einer der Regeln aus Abschnitt 3 entspricht, und benennt Pfad
und verletzte Regel. Mit `--regeln` gibt er zusätzlich die geprüften Regeln aus,
damit der Prüfumfang im Betriebslog dokumentiert ist.

Die Prüfung ist der automatisierte Nachweis, dass aus einem Backup keine
Original-Quelldateien wiederherstellbar sind: Was nicht im Manifest steht, ist
nicht im Archiv, und was im Manifest steht, ist gegen die Ausschlussliste
geprüft.

---

## 7. Restore-Test

Der Restore-Test läuft mindestens vierteljährlich und zusätzlich vor jedem
größeren Release. Er läuft immer gegen eine getrennte Wiederherstellungsumgebung,
niemals gegen die Produktionsdatenbank.

1. Sicherung auswählen und entschlüsseln:
   `gpg --decrypt datenbank-JJJJ-MM-TT-hhmm.sql.gz.gpg | gunzip > restore.sql`
2. Leere Datenbank in der Wiederherstellungsumgebung anlegen.
3. Dump einlesen: `mysql --defaults-extra-file=... restore_ziel < restore.sql`
4. `php artisan migrate --pretend` gegen die wiederhergestellte Datenbank
   ausführen und prüfen, dass keine ausstehende Migration fehlt.
5. Ergebnisartefakte entschlüsseln und in ein Prüfverzeichnis entpacken.
6. Fachliche Stichprobe: eine bezahlte Abrechnung aufrufen, das zugehörige
   Final-PDF öffnen und die Rechnungsnummer gegen die Datenbank abgleichen.
7. Nachweis, dass keine Originaldaten wiederhergestellt wurden:
   - `php artisan smartabrechnen:audit-backup-manifest` gegen das Manifest der
     verwendeten Sicherung,
   - im Prüfverzeichnis darf kein Pfad der Ausschlussliste existieren,
   - in der wiederhergestellten Datenbank muss `temporary_uploads` entweder
     leer sein oder ausschließlich Einträge mit `is_tombstone = 1` und
     `storage_key IS NULL` enthalten.
8. Prüfverzeichnis und Wiederherstellungsdatenbank nach dem Test entfernen.
9. Ergebnis protokollieren: Datum, verwendete Sicherung, Dauer, Befund,
   verantwortliche Person.

### Protokollvorlage

| Datum | Sicherung | Dauer | Manifestprüfung | Stichprobe | Befund | Person |
| --- | --- | --- | --- | --- | --- | --- |
| TT.MM.JJJJ | artefakte-JJJJ-MM-TT-hhmm | ... min | bestanden | bestanden | offen oder erledigt | ... |

---

## 8. Offene Punkte vor Livegang

1. Empfängerschlüssel und Aufbewahrungsort des privaten GnuPG-Schlüssels
   festlegen und dokumentieren.
2. Getrennten Ablageort für die Monatssicherungen festlegen.
3. Aufbewahrungsfristen `EXTRACTED_DATA_RETENTION_DAYS` und
   `GENERATED_PDF_RETENTION_DAYS` festlegen. Solange sie nicht gesetzt sind,
   löscht `smartabrechnen:enforce-retention` ausdrücklich nichts.
4. Frist des Konto-Löschworkflows über `ACCOUNT_DELETION_GRACE_DAYS` bestätigen.
5. Auftragsverarbeitungsverträge mit IONOS, Stripe und dem aktivierten
   KI-Provider prüfen und ablegen.
6. Ersten Restore-Test durchführen und protokollieren.

Die rechtliche Bewertung der Aufbewahrungspflichten und der
Auftragsverarbeitungsverträge ist durch Rechtsanwalt beziehungsweise
Steuerberater vorzunehmen. Diese Anleitung beschreibt ausschließlich den
technischen Betrieb.
