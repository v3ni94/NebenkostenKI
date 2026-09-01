# Löschkonzept für den Betrieb

**Anwendung:** Smart Abrechnen (Nebenkostenabrechnungsportal)
**Betreiber:** Hausverwaltung Müller GmbH
**Stand:** 01.09.2026
**Grundlage:** Masterprompt Abschnitt 6.4, 19 und 24, ARCHITECTURE.md Abschnitt 5

Dieses Dokument beschreibt, welche Daten gelöscht werden, wann, durch welchen
Lauf und wie die Löschung nachgewiesen wird. Es ist die betriebliche Ergänzung
zur Architekturdokumentation und enthält keine Rechtsberatung.

---

## 1. Vier Löschpfade

| Nr. | Gegenstand | Auslöser | Lauf |
| --- | --- | --- | --- |
| 1 | Originaluploads, Seitenbilder, OCR-Texte, Providerdateien | unmittelbar nach der Auswertung, bei endgültigem Fehler, spätestens nach der Kurzzeit-TTL | `smartabrechnen:cleanup-temporary-uploads`, ergänzt durch `smartabrechnen:retry-failed-deletions` |
| 2 | abgelaufene strukturierte Extraktionsdaten und abgelaufene erzeugte PDFs | Ablauf der konfigurierten Aufbewahrungsfrist | `smartabrechnen:enforce-retention` |
| 3 | vollständiges Kundenkonto | Antrag des Nutzers, nach Ablauf der Frist | `smartabrechnen:execute-account-deletions` |
| 4 | Backups | Ablauf der Backup-Retention | Backupskripte, siehe `backup-und-restore.md` |

---

## 2. Kurzzeitbereich

Die Kurzzeit-TTL beginnt mit dem Eingang des ersten Upload-Chunks und ist in
der Konfiguration hart auf 120 Minuten begrenzt. Eine höhere Angabe in der
`.env` wird im Code auf 120 Minuten reduziert.

Nachweis: `source_deletion_events` protokolliert Zeitpunkt, Status, Versuch und
Fehlercode ohne Dateiinhalt und ohne Dateinamen. Eine fehlgeschlagene Löschung
ist ein offener Datenschutzvorfall und im Adminbereich als kritischer Alarm zu
behandeln, nicht als gewöhnliche Warnung.

---

## 3. Aufbewahrungsfristen

Zwei Werte steuern den Lauf:

| Umgebungsvariable | Wirkung |
| --- | --- |
| `EXTRACTED_DATA_RETENTION_DAYS` | Höchstalter der dauerhaft gespeicherten strukturierten Extraktionsdaten und Dokumentseiten |
| `GENERATED_PDF_RETENTION_DAYS` | Höchstalter der erzeugten PDFs |

**Sind die Werte nicht gesetzt, löscht der Lauf ausdrücklich nichts** und weist
darauf hin, dass die Fristen vor Livegang festzulegen sind. Eine fehlende
kaufmännische Festlegung darf nicht dazu führen, dass die Anwendung eine Frist
erfindet und Kundendaten löscht.

Von der Frist ausgenommen sind die Rechnungen der Hausverwaltung Müller GmbH.
Sie unterliegen handels- und steuerrechtlichen Aufbewahrungspflichten.

---

## 4. Konto-Löschworkflow

### Ablauf

1. **Antrag.** Der Nutzer beantragt die Löschung unter „Datenschutz und
   Löschung“ im Konto. Er erhält vorher die Auskunft, was gelöscht wird und was
   erhalten bleibt.
2. **Frist.** Die Frist beträgt standardmäßig 30 Tage und ist über
   `ACCOUNT_DELETION_GRACE_DAYS` konfigurierbar. Zulässiger Bereich: 7 bis 90
   Tage. Der im Antrag protokollierte Termin hat Vorrang, damit eine spätere
   Änderung der Konfiguration einen laufenden Antrag nicht verschiebt.
3. **Rücknahme.** Innerhalb der Frist kann der Nutzer den Antrag jederzeit ohne
   Angabe von Gründen zurücknehmen. Das Konto bleibt bis zum Ablauf der Frist
   uneingeschränkt nutzbar.
4. **Ausführung.** Nach Ablauf der Frist führt
   `smartabrechnen:execute-account-deletions` die Löschung aus. Der Lauf ist im
   Zeitplan täglich um 03:20 Uhr eingeplant, idempotent und wiederaufnehmbar.

### Was gelöscht wird

Konto, Mandant, Objekte, Einheiten, Mietverhältnisse, Zeiträume, Zähler,
Zählerstände, Abrechnungsläufe mit allen strukturierten Auslesedaten,
Kostenpositionen, Verteilerschlüssel, Vorauszahlungen, Prüfhinweise,
Berechnungsstände, Mieterabrechnungen, erzeugte Abrechnungs-PDFs samt Datei in
der Ablage, Vorschauen, ZIP-Pakete, frühere Datenexporte,
Erinnerungseinstellungen, protokollierte Nachrichten, Sitzungen und offene
Kennwortzurücksetzungen.

### Was erhalten bleibt und entkoppelt wird

Die Rechnungen der Hausverwaltung Müller GmbH bleiben erhalten und werden vom
gelöschten Konto entkoppelt: `organization_id`, `user_id`, `billing_run_id` und
`payment_id` werden auf `null` gesetzt. Erhalten bleiben ausschließlich die für
die Aufbewahrung erforderlichen Angaben: Rechnungsnummer, Ausstellungs- und
Leistungsdatum, Rechnungsanschrift zum Zeitpunkt der Leistung, Beträge,
Steuersatz und Status. Die zugehörigen Rechnungs-PDFs werden auf dieselbe Weise
entkoppelt, damit sie das Löschen des Abrechnungslaufs überdauern.

Ebenfalls erhalten bleiben der Löschnachweis über die Quelldaten und die
datensparsamen Einträge im Revisionsprotokoll über Antrag, Rücknahme und
Ausführung. Nach der Ausführung enthält das Protokoll keine Kontaktdaten mehr,
die Verweise auf Nutzer und Mandant sind auf `null` gesetzt.

### Mandanten mit mehreren Mitgliedern

Ist der Nutzer nicht das einzige Mitglied eines Mandanten, wird dieser Mandant
nicht gelöscht. Der Nutzer wird lediglich aus ihm entfernt, damit kein fremder
Datenbestand mit entfernt wird.

### Wiederaufnahme

Bricht ein Lauf ab, bleibt der Antrag im Revisionsprotokoll offen und wird im
nächsten Lauf erneut aufgenommen. Ein Fehlschlag bei einem Konto bricht den
Lauf für die übrigen Konten nicht ab.

---

## 5. Datenexport

Der Nutzer fordert seinen Export selbst an. Das Paket enthält je Entität eine
JSON-Datei, eine lesbare Übersicht als Textdatei sowie die erzeugten
Abrechnungs-PDFs und die Rechnungen der Hausverwaltung Müller GmbH.

Es enthält **keine** Originaldateien, weil diese nach der Auswertung nicht mehr
existieren, und **keine** Daten anderer Mandanten. Die Auslieferung erfolgt über
eine autorisierte Streaming-Route oder einen kurzlebigen signierten Link, deren
Gültigkeit `SIGNED_DOWNLOAD_TTL_MINUTES` bestimmt. Der Export ist selbst ein
erzeugtes Artefakt und unterliegt damit der Aufbewahrungsfrist für erzeugte
PDFs.

---

## 6. Grenzen, die nicht behauptet werden

Es wird nicht behauptet, eine Datei ließe sich auf gemeinsam genutztem oder
SSD-basiertem Storage forensisch überschreiben. Verbindlich sind logische
Löschung, Ausschluss aus allen Backups, kurze Aufbewahrung im Kurzzeitbereich
und ein dokumentierter Löschstatus. Ebenso wird ein Providerparameter
`store: false` nicht als Zero Data Retention bezeichnet.

---

## 7. Offene Punkte

1. Aufbewahrungsfristen festlegen und in der `.env` setzen.
2. Frist des Konto-Löschworkflows bestätigen.
3. Restore-Test durchführen und protokollieren.
4. Rechtliche Prüfung der Aufbewahrungspflichten durch Steuerberater
   beziehungsweise Rechtsanwalt einholen.
