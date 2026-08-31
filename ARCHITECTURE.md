# Architektur: Smart Abrechnen

**Projekt:** KI-gestütztes Nebenkostenabrechnungsportal
**Kanonische Domain:** `https://smart-abrechnen.de`
**Betreiber:** Hausverwaltung Müller GmbH
**Stand dieses Dokuments:** 31.08.2026
**Status:** Phase 0 abgeschlossen, Phase 1 in Arbeit

Dieses Dokument hält die Architekturentscheidungen fest. Es ist die
verbindliche technische Referenz und wird bei jeder wesentlichen Entscheidung
fortgeschrieben. Abweichungen von der Vorgabe sind als ADR begründet.

---

## 1. Produkt in drei Sätzen

Ein Vermieter lädt seine Unterlagen ungeordnet hoch. Das System klassifiziert
die Dokumente, extrahiert die Werte, prüft sie gegeneinander und führt den
Nutzer nur durch die offenen Prüfstellen. Nach Ansicht der wasserzeichen-
behafteten Vorschau bezahlt der Nutzer je erzeugter Mieterabrechnung und
erhält wasserzeichenfreie Final-PDFs.

Der Vermieter bleibt Absender und inhaltlich Verantwortlicher jeder
Betriebskostenabrechnung. Die Plattform ist ein Software-Werkzeug und leistet
keine Rechtsberatung im Einzelfall.

---

## 2. Technologiestack

| Baustein | Entscheidung | Begründung |
| --- | --- | --- |
| Sprache | PHP 8.3+ (`declare(strict_types=1)`, PSR-12) | Vorgabe, IONOS-kompatibel |
| Framework | Laravel 12.68 | Vorgabe; lange Wartung, breite IONOS-Praxis |
| Oberfläche | Blade, serverseitig gerendert | kein Node-Prozess in Produktion nötig |
| CSS | Tailwind CSS 4 mit HVM-Theme-Tokens | Designsystem als Tokens, ein Build-Artefakt |
| JavaScript | Alpine.js plus kleine Module, Build mit Vite | progressive enhancement, keine SPA |
| Datenbank | MariaDB 10.11 LTS und 11.x, InnoDB, utf8mb4 | Vorgabe, siehe ADR-003 |
| Dezimalarithmetik | `brick/math` | ADR-004: bcmath ist auf IONOS nicht garantiert |
| Dateiablage | Flysystem: `temporary_uploads`, `sftp`, optional `s3` | Trennung Kurzzeit- von Dauerspeicher |
| PDF | mPDF | ADR-005: reines PHP, kein Chromium auf Webhosting |
| Zahlung | Stripe Checkout, offizielles PHP-SDK | Vorgabe, gehostete Zahlungsseite |
| KI | Providerabstraktion, REST über Guzzle | ADR-008 |
| E-Mail | SMTP über IONOS (`kontakt@smart-abrechnen.de`) | Vorgabe |
| Queue | datenbankgestützt, Cron-getrieben | ADR-006 |
| Statische Analyse | PHPStan Level 6 mit Larastan, Pint | Codequalität |
| Tests | PHPUnit 11, Unit, Feature, E2E | Definition of Done |

Laravel 12 ist gegenüber Laravel 13 gewählt, weil Laravel 12 in der Vorgabe
ausdrücklich genannt ist, PHP 8.3 unterstützt und zum Projektstart die
breiteste Paketkompatibilität besitzt.

---

## 3. Architekturentscheidungen (ADR)

### ADR-001: Eine Anwendung, klar getrennte Schichten

Die Anwendung ist in sechs Schichten getrennt:

1. **Presentation** (`app/Http`, `resources/views`): Controller, Form Requests,
   Blade, wenige JSON-Endpunkte für Upload und Statusabfragen.
2. **Application** (`app/Application`): Use Cases wie Dokument hochladen,
   Daten bestätigen, Abrechnung berechnen, Checkout starten, Final-PDF erzeugen.
3. **Domain** (`app/Domain`): reine PHP-Objekte und Services für Kosten,
   Schlüssel, Zeitanteile, Rundung, Vorauszahlungen und Ergebnisse.
4. **Rules** (`app/Rules`): versionierte Plausibilitäts- und Warnregeln.
5. **AI** (`app/Services/Ai`): providerunabhängige Klassifikation und Extraktion.
6. **Infrastructure** (`app/Services/*`, `app/Models`): MariaDB, SFTP, SMTP,
   Stripe, PDF, Queue.

Verbindlich: Die Domain-Schicht hat keine Abhängigkeit zu HTTP, Laravel-Facades,
Eloquent, Stripe oder einem KI-Provider. Sie erhält validierte Eingabedaten und
liefert reproduzierbare Ergebnisse samt Rechenweg. Das ist die Voraussetzung
dafür, dass die Berechnung ohne Infrastruktur testbar ist und ein bezahlter
Berechnungsstand jederzeit reproduziert werden kann.

### ADR-002: Öffentliches Frontend und Anwendung in einer Laravel-Instanz

**Entscheidung:** Website und Anwendung laufen in derselben Laravel-Anwendung
unter derselben kanonischen Domain.

```
https://smart-abrechnen.de/              öffentliches Frontend, indexierbar
https://smart-abrechnen.de/impressum     Rechtstexte
https://smart-abrechnen.de/app/...       die Anwendung, nur authentifiziert
https://smart-abrechnen.de/admin/...     interner Adminbereich, 2FA
https://smart-abrechnen.de/webhooks/...  Stripe, ohne Session und ohne CSRF
```

`www.smart-abrechnen.de` leitet dauerhaft (301) auf die kanonische Domain um.

**Begründung:** IONOS Profil A stellt einen Document Root je Vertrag bereit.
Ein Subdomain-Split (`app.smart-abrechnen.de`) würde ein zweites Zertifikat,
einen zweiten Deployzweig, eine Cookie-Domain-Entscheidung und
Cross-Origin-Sonderfälle beim Upload erzwingen, ohne fachlichen Gewinn. Die
Trennung erfolgt stattdessen durch Route-Prefix, getrennte Blade-Layouts,
getrennte Middleware-Gruppen und getrennte Session-Bereiche für Kunden und
Administratoren.

**Konsequenz:** Public- und Portalrouten dürfen sich kein Layout und keine
Navigation teilen, damit ein öffentlicher View niemals versehentlich
Kundendaten rendert. Der PHP-Namespace `Public` ist reserviert, die Controller
heißen deshalb `App\Http\Controllers\Site` und `...\Portal`.

### ADR-003: MariaDB-Connection statt MySQL-Connection

**Entscheidung:** Standard ist `DB_CONNECTION=mariadb`.

**Begründung:** Die Vorgabe nennt `mysql`, weil Laravel technisch den
MySQL-PDO-Treiber verwendet. Laravel 12 besitzt zusätzlich einen eigenen
`mariadb`-Connection-Typ, der dieselbe PDO-Erweiterung nutzt, aber
MariaDB-konforme Schema-Grammatik erzeugt (unter anderem bei
`uuid`/`ulid`-Spalten und Default-Ausdrücken). Da MariaDB 10.11 und 11.x
ausdrücklich das Ziel sind, ist der spezifischere Treiber die sicherere Wahl.
`mysql` bleibt lauffähig und ist als Rückfalloption dokumentiert.

**Blocker-Regel:** Stellt der gebuchte IONOS-Tarif ausschließlich MySQL
bereit, ist das vor Deployment als Blocker zu melden. MariaDB wird dann auf
einem passenden IONOS-Server oder als externe verwaltete Datenbank
bereitgestellt. Eine stillschweigende Umstellung auf MySQL findet nicht statt.
Der Admin-Healthcheck gibt die tatsächliche Serverversion aus und prüft auf
10.11 oder unterstütztes 11.x.

### ADR-004: `brick/math` statt bcmath für die Dezimalarithmetik

**Entscheidung:** Interne Zwischenrechnungen mit hoher Dezimalpräzision laufen
über `brick/math`.

**Begründung:** Die Verteilungsrechnung braucht exakte Dezimalarithmetik. Die
PHP-Erweiterungen `bcmath` und `gmp` sind auf IONOS Webhosting nicht garantiert
verfügbar; in der Entwicklungsumgebung dieses Projekts sind sie es nicht.
`brick/math` besitzt einen reinen PHP-Rechenkern und nutzt `gmp`/`bcmath`
automatisch, wenn sie vorhanden sind. Damit ist das Ergebnis unabhängig von der
Zielumgebung identisch.

**Konsequenz:** Geldbeträge sind ausschließlich Integer in Cent. Floats sind in
der Berechnung unzulässig. Flächen, Anteile und Verbrauchswerte werden als
`DECIMAL` gespeichert und als `BigDecimal` gerechnet.

### ADR-005: mPDF statt Headless Chromium

**Entscheidung:** PDFs werden aus HTML/CSS mit mPDF gerendert.

**Begründung:** IONOS Webhosting erlaubt keinen dauerhaften Chromium-Prozess
und keine verlässliche Installation von Systemabhängigkeiten. mPDF ist reines
PHP, benötigt nur `gd` und `mbstring` und deckt den benötigten Umfang ab:
mehrseitige Tabellen, Kopf- und Fußzeilen, Seitennummerierung, Wasserzeichen,
eingebettete Schriften.

**Bewertete Alternativen:**

- *Headless Chromium (Browsershot/Puppeteer):* pixelgenauer, in Profil A nicht
  betreibbar. Verworfen.
- *`@react-pdf/renderer`:* setzt eine Node-Laufzeit in Produktion voraus.
  Verworfen.
- *TCPDF/FPDF:* kein brauchbares HTML/CSS-Layout. Verworfen.

**Konsequenz:** Layouts werden mit tabellenorientiertem, konservativem CSS
gebaut (kein Flexbox/Grid, keine CSS-Variablen im PDF-Template). Das
Wasserzeichen wird serverseitig eingebrannt (mPDF `SetWatermarkText`), nicht als
entfernbare Browser-Ebene. **PDF/A wird nicht behauptet**, solange es nicht
nachweislich zuverlässig unterstützt ist.

### ADR-006: Datenbankgestützte Queue mit Cron-getriebenen kurzen Läufen

**Entscheidung:** Queue-Treiber ist `database`. In Profil A ruft ein
IONOS-Cronjob regelmäßig `schedule:run` auf; der Scheduler startet
`queue:work --stop-when-empty --max-time=50`.

**Begründung:** Profil A garantiert weder Redis noch einen dauerhaften
Worker-Prozess. Jeder langlaufende Vorgang wird deshalb in wiederanlaufbare,
idempotente Teiljobs zerlegt, mit Lease, Heartbeat, Retry, exponentiellem
Backoff und Dead-Letter-Status.

**Konsequenz:** Die Oberfläche stellt die tatsächliche Cron-Auflösung ehrlich
dar. Ist nur ein Fünf-Minuten-Intervall verfügbar, wird das im Statusdialog
kommuniziert und nicht als Echtzeitverarbeitung dargestellt. Stripe-Webhooks
werden davon unabhängig sofort per HTTPS verarbeitet, weil sie nicht über die
Queue laufen dürfen.

### ADR-007: Originaldateien sind Kurzzeitdaten, nicht Bestandsdaten

**Entscheidung:** Hochgeladene Originaldateien werden ausschließlich auf der
Disk `temporary_uploads` gehalten und nach der Auswertung gelöscht. Dauerhaft
gespeichert werden nur strukturierte Extraktionsdaten und minimale
Löschnachweise.

Das ist die prägende Architekturentscheidung des Projekts, siehe Abschnitt 5.

### ADR-008: Providerabstraktion mit REST-Client statt Provider-SDK

**Entscheidung:** `AiDocumentProviderInterface` mit den Implementierungen
`OpenAiResponsesProvider` und `AnthropicMessagesProvider`, jeweils über einen
PSR-18-konformen Guzzle-Client gegen die offiziellen HTTP-APIs.

**Begründung:** Es werden zwei Provider mit unterschiedlichen Datei-,
Struktur- und Löschsemantiken benötigt, dazu ein einheitliches Kostenprotokoll,
eine einheitliche Schemavalidierung und ein einheitlicher Löschpfad für
Provider-Dateien. Ein direkter REST-Client hält diese Semantik in einer Hand
und vermeidet die Abhängigkeit von SDK-Zyklen zweier Anbieter.
Ungewartete inoffizielle Wrapper werden nicht eingesetzt.

**Konsequenz:** Request- und Response-Bodies werden niemals dauerhaft
gespeichert oder in Logs, Error Monitoring oder Queue-Payloads geschrieben.
Nach der Schemavalidierung werden nur die freigegebenen strukturierten Felder
persistiert und die rohe Modellantwort verworfen.

### ADR-009: Modellwahl und Modellnamen

**Entscheidung:** Modelle sind ausschließlich über ENV konfigurierbar. Die
Vorgabewerte sind:

| Zweck | Provider | Vorgabe | Begründung |
| --- | --- | --- | --- |
| Klassifikation, einfache Extraktion | Anthropic | `claude-haiku-4-5` | hohes Volumen, niedrigste Tokenkosten |
| Verträge, komplexe Tabellen, Reconciliation | Anthropic | `claude-sonnet-5` | siehe unten |
| Klassifikation, einfache Extraktion | OpenAI | `gpt-5.6-luna` | Vorgabewert |
| komplexes Dokumentverständnis | OpenAI | `gpt-5.6-terra` | Vorgabewert |

**Abweichung von der Vorgabe:** Für die Analyse ist `claude-sonnet-5` statt
`claude-sonnet-4-6` gesetzt. `claude-sonnet-5` ist das neuere Modell, ist
leistungsfähiger und kostet je Million Token weniger (rund 2 USD Input und
10 USD Output gegenüber 3 USD und 15 USD). Die Abweichung senkt also Kosten und
erhöht die Qualität. `claude-sonnet-4-6` bleibt gültig und ist per ENV
einsetzbar.

**Verbindliche Regeln:** Modellnamen und Preisannahmen sind veränderlich. Ein
Admin-Healthcheck prüft vor Livegang und danach regelmäßig, ob das
konfigurierte Modell beim Provider tatsächlich verfügbar ist. Kein
Modellwechsel ohne Protokollierung der Modell-ID und der Promptversion in
`ai_calls`. Die Kalkulationsbasis in `config/ai.php` ist eine dokumentierte
Annahme für interne Kostenlimits, keine Abrechnungsgrundlage.

### ADR-010: Preise werden brutto angezeigt und serverseitig neu berechnet

Verbraucherpreise erscheinen immer inklusive Umsatzsteuer. Netto, Umsatzsteuer
und Brutto werden im Checkout und auf der HVM-Rechnung getrennt ausgewiesen.
Vor der Vorschau ist eine unverbindliche Schätzung erlaubt; unmittelbar vor dem
Stripe Checkout wird der Endpreis anhand der tatsächlich erzeugten
Mieterabrechnungen serverseitig neu berechnet. Der Browser-Redirect ist niemals
Zahlungsnachweis, nur ein signaturgeprüfter Webhook schaltet die Finalisierung
frei.

---

## 4. Ordnerstruktur

```
app/
  Application/        Use Cases (BillingRun, Documents, Payment, Reminder, Account)
  Domain/             reine Berechnungslogik, ohne Framework-Abhängigkeit
    Allocation/       Verteilerschlüssel
    Calculation/      Engine, DTOs, Ergebnisobjekte, Heizkostenmodul
    Money/            Geld als Integer-Cent-Value-Object
    Period/           taggenaue Zeiträume, Schaltjahr, Schnittmengen
  Enums/              persistierte Statuswerte
  Http/
    Controllers/Site/     öffentliches Frontend
    Controllers/Portal/   die Anwendung
    Controllers/Admin/    interner Bereich
    Controllers/Webhook/  Stripe
  Models/             Eloquent, mandantenbezogen
  Policies/           Object-Level-Autorisierung
  Rules/              versionierte Prüfregeln (Definitions, Engine)
  Services/
    Ai/               Providerabstraktion, JSON-Schemata, Prompts
    Payment/          Stripe
    Pdf/              Renderer und Wasserzeichen
    Queue/            Lease, Heartbeat, Dead Letter
    Storage/          Kurzzeitbereich, SFTP, Löschpfade
config/
  ai.php              Provider, Schwellenwerte, Löschfristen, Kostenbasis
  smartabrechnen.php  Betreiber, Preise, Uploads, Aufbewahrung, Toleranzen
database/migrations/  vorwärtskompatibel, rollbackfähig
docs/adr/             ergänzende Entscheidungsdokumente
resources/views/
  site/ legal/        öffentliches Frontend und Rechtstexte
  portal/ admin/      Anwendung und interner Bereich
  pdf/                Abrechnungs- und Rechnungstemplates
  layouts/ components/
routes/
  web.php             öffentliches Frontend, Rechtstexte, Bereichseinstieg
  portal.php admin.php auth.php
storage/app/temporary-uploads/   Kurzzeitbereich, aus Backups ausgeschlossen
tests/
  Unit/Domain/        Berechnungslogik
  Feature/            HTTP, Autorisierung, Datenmodell, Webhooks
  Fixtures/           handprüfbare Referenzbeispiele
```

---

## 5. Datenfluss und Löschkonzept

Das Löschkonzept ist keine Zusatzfunktion, sondern bestimmt das Datenmodell.

### 5.1 Lebenszyklus einer hochgeladenen Datei

```
Browser
  │  chunked Upload über HTTPS
  ▼
temporary_uploads  (lokal, verschlüsselt, außerhalb Webroot, ohne Backup)
  │  1. MIME, Magic Bytes, Größe, Struktur
  │  2. neutrale Quellenbezeichnung, Originalname nur temporär
  │  3. SHA-256 temporär, daraus HMAC-SHA-256-Fingerabdruck dauerhaft
  │  4. Malwareprüfung (clamav | external | disabled)
  │  5. Seitenzahl und minimal nötige technische Metadaten
  ▼
KI-Provider  (Datei nur für diesen Extraktionslauf)
  │  6. Klassifikation
  │  7. Extraktion gegen striktes JSON-Schema
  │  8. maximal 2 kontrollierte Reparaturversuche
  ▼
Persistenz  (dauerhaft, minimal)
  │  extracted_fields: Schema-Key, Wert, Seite, kurzer Fundstellenausschnitt,
  │                    Konfidenz, Provider, Modell, Promptversion, Status
  │  documents:        neutrale Bezeichnung, Typ, MIME, Größe, Seitenzahl,
  │                    HMAC-Fingerabdruck, Verarbeitungs- und Löschstatus
  ▼
Löschung  (sofort, nicht später)
     9. Provider-Datei über die Löschschnittstelle entfernen
    10. lokale Originaldatei, Seitenbilder, Konvertierungen und den
        vollständigen OCR-Text löschen
    11. bei endgültigem Fehler ebenfalls sofort löschen
    12. unabhängiger Cleanup-Job löscht spätestens nach der Kurzzeit-TTL,
        auch wenn die Verarbeitung hängen geblieben ist
    13. source_deletion_events protokolliert Zeitpunkt, Status und Fehlercode
        ohne Dateiinhalt
```

Die Kurzzeit-TTL beginnt mit dem Eingang des ersten Upload-Chunks und ist in
`config/smartabrechnen.php` hart auf 120 Minuten begrenzt. Eine höhere
Konfiguration wird im Code auf 120 Minuten reduziert, damit eine fehlerhafte
`.env` das Konzept nicht aufweichen kann.

### 5.2 Was dauerhaft gespeichert wird, und was nicht

| Dauerhaft | Niemals dauerhaft |
| --- | --- |
| strukturierte Extraktionsdaten mit Quellenbezug | Original-PDFs, Bilder, Office- und ZIP-Dateien |
| neutrale Quellenbezeichnung, Typ, Seitenzahl | Originaldateiname |
| kurzer Fundstellenausschnitt je Feld | vollständiger OCR-Text, vollständige Text-Layer |
| HMAC-Fingerabdruck zur Dublettenerkennung | Seitenbilder und Vorschaubilder der Quelldokumente |
| Konfidenz, Provider, Modell, Promptversion | rohe Prompts, rohe Antworten, Base64-Inhalte |
| Nutzerbestätigung, Korrektur, Prüfstatus | EXIF und andere nicht benötigte Metadaten |
| Calculation Snapshots, erzeugte PDFs, HVM-Rechnungen | temporäre Provider-Datei-IDs nach Abschluss |

Ein SHA-256 des Dateiinhalts wird nicht dauerhaft gespeichert, weil er ein
Wiedererkennungsmerkmal des Originals wäre. Für die Dublettenerkennung dient
ein schlüsselgebundener HMAC-SHA-256-Fingerabdruck.

### 5.3 Grenzen, die nicht behauptet werden

Es wird nicht behauptet, eine Datei könne auf gemeinsam genutztem oder
SSD-basiertem Storage forensisch überschrieben werden. Verbindlich sind:
logische Löschung, Ausschluss aus allen Backups, kurze TTL und dokumentierter
Löschstatus. Ebenso wird das Setzen von `store: false` oder ein
API-Löschaufruf im UI nicht als Zero Data Retention bezeichnet.

---

## 6. Berechnung und Reproduzierbarkeit

- KI extrahiert und erklärt. **Geldbeträge und Mieteranteile berechnet
  ausschließlich deterministischer PHP-Code.**
- Interne Rechnung mit hoher Dezimalpräzision, Rundung auf Cent erst am Ende
  einer Kostenzeile.
- Rundungsdifferenzen werden mit einem deterministischen
  Largest-Remainder-Verfahren verteilt und je Zeile in
  `rounding_adjustment_cent` festgehalten. Die Summe der Einzelanteile
  entspricht exakt der zu verteilenden Summe.
- Zeitanteile taggenau, Start- und Endtag inklusive, Schaltjahr korrekt.
- Leerstandsanteile trägt der Eigentümer und werden getrennt ausgewiesen.
- Fehlende Werte bleiben `null` und erzeugen eine Prüfaufgabe. Es wird nie
  geschätzt.
- Ein bezahlter Berechnungsstand wird als unveränderlicher
  `calculation_snapshot` gesichert (normalisierte Eingabe, Ergebnis,
  Domain-Version, Ruleset-Version, Hash). Final-PDFs werden aus diesem
  Snapshot neu erzeugt, nicht durch Entfernen eines Wasserzeichens.
- Gesetzes- und Regelstände sind mit Gültigkeitsdatum versioniert, damit alte
  Abrechnungen nach einer Gesetzesänderung reproduzierbar bleiben.
- Adminänderungen an Preis, Regel oder Prompt wirken nur auf neue
  Berechnungsstände.

---

## 7. Threat Model

Betrachtet werden die Angreifer: fremder Internetnutzer, angemeldeter Kunde
eines anderen Mandanten, Inhalt eines hochgeladenen Dokuments,
kompromittierter Provider-Kanal, interner Supportzugang.

| Nr. | Bedrohung | Gegenmaßnahme | Nachweis |
| --- | --- | --- | --- |
| T1 | Fremdzugriff auf Abrechnungen anderer Mandanten | jede Query nach User/Organization gescoped, Policy und Object-Level-Check in jeder Route und jedem Use Case, ULID statt fortlaufender ID | Feature-Test zwischen zwei Mandanten |
| T2 | Erraten von Download-URLs | Auslieferung nur über authentifizierte Streaming-Routen oder kurzlebige signierte Links, Ownership-Check je Abruf | Feature-Test auf 403/404 |
| T3 | Prompt Injection aus einem Dokument | Sicherheitsbaustein in jedem Systemprompt, Dokumentinhalt gilt als untrusted data, Ausgabe nur gegen strenges JSON-Schema, keine Toolausführung aus Dokumentinhalt | Contracttest mit präparierter Fixture |
| T4 | Schadsoftware im Upload | MIME- und Magic-Byte-Prüfung, Größenlimits, Malware-Adapter, kein Ausführen von Uploads, Archive gegen Traversal und Zip-Bomben geschützt | Feature-Test je Angriffsklasse |
| T5 | Originaldaten überleben die Verarbeitung | getrennte Disk ohne Backup, Löschung nach Extraktion und bei endgültigem Fehler, unabhängiger TTL-Cleanup, Löschprotokoll, Datenschutzmonitor im Adminbereich | Feature- und E2E-Test auf Nichtexistenz |
| T6 | Personenbezogene Daten in Logs oder Monitoring | Request- und Response-Bodies nie dauerhaft, sensible Felder redigiert, Queue-Payloads ohne Dateiinhalt, gekürzte IP-Adressen | Test, dass Log- und Payloadfelder frei von Inhalten sind |
| T7 | Freischaltung ohne Zahlung | Finalisierung nur nach signaturgeprüftem Webhook, Betrag, Währung und BillingRun werden verglichen, Event-ID unique, idempotente Verarbeitung | Webhook-Tests: falsche Signatur, falscher Betrag, Wiederholung |
| T8 | Doppelte oder lückenhafte Rechnungsnummern | atomare Vergabe in Transaktion mit Zeilensperre, Unique-Constraint, Storno nur über Stornorechnung | Parallelzugriffstest |
| T9 | Credential Stuffing und Brute Force | Argon2id, Rate Limits auf Login, Reset, Upload, KI und Download, sichere Sessions, 2FA für Admins verpflichtend | Feature-Test auf Sperrverhalten |
| T10 | Missbrauch des Supportzugangs | getrennte Admin-Rollen und Sessions, Zugriff nur mit Begründung, jeder Einblick erzeugt einen Audit-Eintrag | Audit-Test |
| T11 | Kostenexplosion durch KI-Aufrufe | Token- und Kostenprotokoll je Call, Nutzer-, Tages- und Lauflimits, kleines Modell für einfache Aufgaben, keine Wiederverarbeitung identischer Dateien, Adminwarnung | Test der Limitgrenzen |
| T12 | Datenexport an einen Provider ohne Freigabe | `AI_REQUIRE_ZERO_DATA_RETENTION=true` produktiv verbindlich, Provider bleibt blockiert solange `AI_DATA_RETENTION_APPROVED=false`, Fallback darf die Sperre nicht umgehen | Test, dass ein nicht freigegebener Provider produktiv blockiert |

---

## 8. IONOS-Annahmen und Betriebsprofile

### Profil A: IONOS Webhosting, verbindlicher Kompatibilitätsmodus

Die Anwendung muss in diesem Profil vollständig funktionieren.

- Apache beziehungsweise die von IONOS bereitgestellte Webserverumgebung
- PHP-FPM/CGI, Zielversion 8.3 oder neuer
- keine Node-Laufzeit in Produktion, keine Redis-Instanz
- datenbankgestützte Queue, kurze Job-Pakete per Cronjob
- Composer-Abhängigkeiten und Frontend-Build in CI erzeugt, per SFTP ausgeliefert

### Profil B: IONOS VPS, Cloud oder Managed Server

Gleiche Anwendung und gleicher Datenbestand, zusätzlich optional Supervisor für
Worker, Redis für Cache und Queue, ClamAV für Malwareprüfung. Optimierungen aus
Profil B dürfen keine fachlich abweichenden Ergebnisse erzeugen.

### Vom Betreiber im IONOS-Konto zu ermitteln, nicht zu raten

| Angabe | Wo | Verwendung |
| --- | --- | --- |
| tatsächliche PHP-Version und CLI-Pfad | Control-Center, PHP-Einstellungen | Cronjob-Kommando, `composer.json`-Plattformprüfung |
| Document Root und absoluter Pfad | Control-Center, Webspace | Deployment-Zielpfad, Releasezeiger |
| SFTP-Host, Benutzer, Zielpfad | Control-Center, FTP/SFTP-Zugänge | Deployment und Artefaktspeicher |
| MariaDB-Host, Version, Zugangsdaten | Control-Center, Datenbanken | `.env`, Healthcheck |
| kleinstes verfügbares Cron-Intervall | Control-Center, Cronjobs | Statusanzeige und Erwartungsmanagement |
| SMTP-Daten und Postfachpasswort | Control-Center, E-Mail | Transaktionsmails |

Cronjob-Kommando (Pfad und PHP-Binary aus dem IONOS-Konto einsetzen):

```bash
/usr/bin/php8.3 /absoluter/pfad/zum/release/artisan schedule:run
```

Der Scheduler startet daraus die Queue mit begrenzter Laufzeit
(`queue:work --stop-when-empty --max-time=50`) und den TTL-Cleanup.

---

## 9. Teststrategie

| Ebene | Umfang | Anmerkung |
| --- | --- | --- |
| Unit | vollständige Domain-Berechnung, mindestens 50 realistische Fälle | ohne Datenbank, ohne Framework |
| Fixtures | drei handprüfbare Referenzbeispiele mit offengelegtem Rechenweg | Eigentumswohnung, Mehrfamilienhaus, Gutschrift/Dublette |
| Feature | Autorisierung zwischen Mandanten, Datenmodell, Upload- und Löschpfade, Webhooks, Rechnungsnummernkreis, Erinnerungen | SQLite in-memory lokal |
| Integration | Migrationen gegen MariaDB 10.11 und 11.x, SFTP-Adapter gegen Testserver, SMTP als Fake | nur in CI, siehe unten |
| Contract | OpenAI und Anthropic gegen gespeicherte anonymisierte Antworten | keine kostenpflichtigen Calls im Standardlauf |
| E2E | Registrierung bis Final-PDF, zusätzlich Nachweis der Originaldateilöschung | plus Abbruch- und Fehlerwege |

**Bekannte Einschränkung der Entwicklungsumgebung:** In der Sandbox dieses
Projekts steht kein MariaDB-Server zur Verfügung. Lokale Tests laufen daher auf
SQLite in-memory. Migrationen sind deshalb treiberneutral zu schreiben;
MariaDB-spezifische Konstrukte werden mit einer Treiberweiche gekapselt. Der
verbindliche Nachweis gegen MariaDB 10.11 und 11.x erfolgt in GitHub Actions
mit einem MariaDB-Service. Ein grüner lokaler Lauf ist kein Ersatz dafür.

---

## 10. Umsetzungsphasen und Status

| Phase | Inhalt | Status |
| --- | --- | --- |
| 0 | Architektur, ADRs, Datenfluss, Threat Model, Ordnerstruktur, ENV-Schema, Designsystem, IONOS-Annahmen | abgeschlossen |
| 1 | Konto und Mandanten, Datenmodell, Objekte und Mietverhältnisse, BillingRun-State-Machine, Chunk-Upload, Lösch-Lifecycle, DB-Jobs | in Arbeit |
| 2 | Providerinterface, OpenAI, Anthropic, Schemata mit Quellenbezug, Hausgeld-, Grundsteuer-, Mietvertrags-, Vorjahres- und Heizkostenextraktion, Dubletten und Reconciliation, Prüfoberfläche | offen |
| 3 | Domain-Engine, Regeln und Warnungen, Wizard, Schnellweg, Vollobjektweg, PDF mit Wasserzeichen und Eigentümerübersicht | Engine vorgezogen, Rest offen |
| 4 | Preislogik, Stripe Checkout und Webhooks, Finalisierung und ZIP, HVM-Rechnung, IONOS-SMTP und Templates, Folgejahresübernahme und Erinnerungen | offen |
| 5 | Adminbereich und Blocker, DSGVO-Export und Löschung, Backups und Restore-Test, GitHub Actions und SFTP-Deployment, Last-, Sicherheits- und E2E-Tests, Abnahme | offen |

Die Berechnungsengine aus Phase 3 wird bewusst früh gebaut, weil sie die
fachliche Grundlage ist und ohne Infrastruktur testbar bleibt.

---

## 11. Offene Punkte und Risiken

| Risiko | Bewertung | Umgang |
| --- | --- | --- |
| Der gebuchte IONOS-Tarif stellt nur MySQL bereit | hoch, blockiert Deployment | vor Deployment prüfen, MariaDB auf Server oder extern bereitstellen, siehe ADR-003 |
| Keine ZDR-Freigabe beim KI-Provider | hoch, blockiert produktiven KI-Betrieb | Provider bleibt technisch blockiert, `AI_DATA_RETENTION_APPROVED=false` |
| Cron nur im Fünf-Minuten-Intervall | mittel, verlängert Verarbeitungszeit | UI stellt die tatsächliche Auflösung ehrlich dar |
| HEIC-Konvertierung ohne Imagick | mittel | serverseitige Konvertierung nur bei vorhandener Erweiterung, sonst klare Fallback-Anweisung an den Nutzer |
| Rechtstexte ohne anwaltliche Freigabe | hoch, blockiert Livegang | Platzhalter mit Blocker im Adminbereich |
| Steuer- und Bankdaten der HVM nicht bestätigt | hoch, blockiert Rechnungserzeugung | sichtbarer Platzhalter und Admin-Blocker |
| Externe Heizkostenabrechnungen sind formatvielfältig | mittel, Extraktionsqualität | Prüfsumme gegen Gesamtbetrag, Abweichung blockiert Finalisierung |
| Gewerbemietverhältnisse | mittel | im Datenmodell vorbereitet, keine automatische Finalisierung, klarer Hinweis |
| mPDF-Layouttreue bei sehr langen Tabellen | niedrig | Seitenumbruchtests je Template, konservatives CSS |
| CSP benötigt `unsafe-eval` für den Alpine-Standardbuild | mittel | offener Punkt: Wechsel auf `@alpinejs/csp` und Umschreiben der `x-data`-Ausdrücke, danach `unsafe-eval` entfernen |
| Registrierung verrät über die Eindeutigkeitsprüfung, ob eine E-Mail bereits ein Konto hat | mittel | offener Punkt: einheitliche Bestätigungsmeldung unabhängig vom Ergebnis, bei vorhandenem Konto stattdessen eine Hinweismail an die Adresse |
| Einwilligungen werden noch nicht in `legal_acceptances` protokolliert | mittel | offener Punkt: Textversion, Zweck, Zeitpunkt, gekürzte IP und gehashter User-Agent bei Registrierung und im Checkout schreiben |

---

## 12. Verbindliche Grundsätze, die jede Änderung einhalten muss

1. KI klassifiziert, extrahiert, ordnet zu, erklärt und plausibilisiert.
   Beträge und Anteile rechnet ausschließlich deterministischer PHP-Code.
2. Kein erkannter Wert ohne Quellenbezug: Dokumenttyp, Seite, minimale
   Fundstelle, Konfidenz, Provider, Modell, Zeitpunkt.
3. Originaldateien werden nie dauerhaft archiviert, nie auf SFTP oder S3
   übertragen, nie in Backups aufgenommen, nie im Konto zum Abruf gehalten.
4. Kein vollständiger OCR-Text, keine kompletten Seitenbilder, keine EXIF-Daten
   und keine rohen KI-Anfragen oder Antworten dauerhaft.
5. Fehlende Werte bleiben `null` und erzeugen eine Prüfaufgabe. Nie schätzen.
6. Keine Einzelfall-Rechtsberatung, keine Werbung mit garantierter
   Rechtssicherheit. Die Verantwortung bleibt beim Vermieter.
7. Keine Zugangsdaten, Keys, Passwörter oder echten personenbezogenen
   Testdaten in Code, Commits, Logs oder Fixtures.
8. Geld als Integer in Cent, Flächen und Anteile als exakte Dezimalwerte,
   niemals als binäre Floats.
9. Abrechnungsrelevante Änderungen werden versioniert und revisionssicher
   protokolliert, bezahlte Stände als unveränderlicher Snapshot.
10. Die Anwendung funktioniert ohne dauerhaften Node-Prozess und ohne
    zwingend dauerhaften Queue-Worker.
11. Oberfläche, E-Mails und PDFs sind vollständig deutsch, klar, seriös, in
    Sie-Ansprache, ohne Gedankenstriche in deutschen Texten.
12. Ist eine Vorgabe technisch oder rechtlich nicht eindeutig, gilt die
    sicherste konservative Variante, die Stelle wird als prüfpflichtig
    gekennzeichnet und die Entscheidung dokumentiert.
