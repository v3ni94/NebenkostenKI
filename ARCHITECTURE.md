# Architektur: Smart Abrechnen

**Projekt:** KI-gestütztes Nebenkostenabrechnungsportal
**Kanonische Domain:** `https://smart-abrechnen.de`
**Betreiber:** Hausverwaltung Müller GmbH
**Stand dieses Dokuments:** 04.09.2026
**Status:** Phasen 0 bis 5 umgesetzt, dazu die manuelle Heizkostenerfassung für
Fall B (ADR-014), der Zweitfaktor (ADR-015), die Verschlüsselung des
Kurzzeitbereichs und das Installationspaket für IONOS. Am 04.09.2026 wurde eine
Vollprüfung mit adversarialer Gegenprüfung durchgeführt
([docs/pruefbericht-2026-09-04.md](docs/pruefbericht-2026-09-04.md)); alle 85
bestätigten und 23 nachverifizierten Befunde sind behoben, drei Funktionen sind
für den Start bewusst ausgeschlossen (ADR-016 bis ADR-018). Die adversariale Nachprüfung
der Behebungen (16 unabhängige Prüfer) ergab 39 Folgepunkte, die in einer zweiten Runde
behoben wurden; eine dritte Nachprüfung ergab 14 mittlere Restpunkte, die in Runde 3 behoben
wurden. 2.421 Tests mit 15.116 Assertions grün, PHPStan Level 6 projektweit fehlerfrei, Pint sauber. Der
Livegang ist durch Betreiberangaben und den Staging-Nachweis blockiert, nicht
durch offene Entwicklungsarbeit. Die frühere Abschlussprüfung ist in
[docs/abnahme.md](docs/abnahme.md) protokolliert.

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

### ADR-011: Eigene Zählertabelle für den Rechnungsnummernkreis

**Entscheidung:** Der Nummernkreis der HVM-Rechnungen führt eine eigene Tabelle
`invoice_number_sequences` mit genau einer Zählerzeile je Präfix und Jahr. Die
Vergabe sperrt diese vorhandene Zeile mit `lockForUpdate()` und erhöht sie in
derselben Transaktion.

**Begründung:** Geprüft wurde zuerst die naheliegende Variante, den höchsten
Wert direkt aus `invoices` zu lesen. Sie genügt nicht. Beim ersten Beleg eines
Jahres existiert keine Zeile, die gesperrt werden könnte. Eine Sperre auf eine
leere Ergebnismenge ist keine Zeilensperre, sondern hängt am Sperrverhalten der
jeweiligen Datenbank: InnoDB setzt eine Next-Key-Sperre, SQLite kennt beides
nicht. Ein Verhalten, das nur auf einer Engine zufällig richtig ist, ist für
einen lückenlosen Nummernkreis zu wenig. Hinzu kommt, dass der höchste Wert aus
einer Zeichenkette zurückgerechnet werden müsste; eine Änderung von Präfix oder
Stellenzahl würde aus einem Sortierproblem eine Lücke oder eine Dublette machen.

**Konsequenzen:** Der Zähler wird niemals verringert und niemals zurückgesetzt.
Eine Korrektur läuft ausschließlich über eine Stornorechnung mit eigenem Beleg
und eigener Nummer aus demselben Kreis. Der eindeutige Schlüssel auf
`invoices.number` bleibt als zweite, unabhängige Sicherung bestehen, damit auch
ein Programmierfehler keine Dublette festschreiben kann. Nachweis:
`Feature/Payment/InvoiceNumberSequenceTest` mit zehn Testmethoden, darunter
Parallelzugriff, Lückenlosigkeit und Jahreswechsel.

### ADR-012: Statusfortschritt als eigener Dienst, nicht als Sitzungszustand

**Entscheidung:** Der erreichte Schritt des geführten Ablaufs wird von
`App\Application\Wizard\WizardProgress` in der Spalte `billing_runs.wizard_step`
persistiert, nicht in der Session. Ein bereits weiter fortgeschrittener Stand
wird beim Aufruf eines früheren Schritts nicht zurückgesetzt.

**Begründung:** Der Ablauf muss nach Vorgabe vollständig fortsetzbar sein, auch
nach Sitzungsende, Gerätewechsel oder einem Tag Pause. Ein Sitzungszustand
verliert genau dann den Fortschritt, wenn er gebraucht wird. Die Ablage am
Abrechnungslauf bindet den Fortschritt an den Vorgang statt an den Browser.
Derselbe Dienst leitet zusätzlich die verbindliche Statussprache je Schritt ab,
damit Fortschrittsleiste und Schrittseiten nicht auseinanderlaufen können.

**Konsequenzen:** Die Zurück-Navigation ist immer erlaubt und folgenlos. Jeder
Schritt speichert über eine gewöhnliche POST-Route mit anschließender
Weiterleitung, ein Neuladen erzeugt also keine doppelte Speicherung. Ein Status
wird nie allein über Farbe kommuniziert. Nachweis:
`Feature/Wizard/WizardFrameTest::test_der_ablauf_ist_unterbrechbar_und_ohne_datenverlust_fortsetzbar`
und `::test_der_erreichte_schritt_wird_gespeichert_und_nicht_zurueckgesetzt`.

### ADR-013: Final-PDFs entstehen auf demselben Renderweg wie die Vorschau

**Entscheidung:** Vorschau und Finalversion werden von derselben
Renderermethode `html()` erzeugt und unterscheiden sich ausschließlich in den
`PdfRenderOptions`, also in Artefakttyp, Dateiname und Wasserzeicheneinstellung.
Es gibt keinen Weg, der ein bestehendes PDF entgegennimmt und nachbearbeitet.

**Begründung:** Die naheliegende Alternative, die Finalversion durch Entfernen
des Wasserzeichens aus der Vorschau zu gewinnen, ist doppelt untragbar. Sie
setzt voraus, dass das Wasserzeichen eine trennbare Ebene ist, womit es auch im
Browser entfernbar wäre und seinen Zweck verliert. Und sie würde den bezahlten
Stand von einer Datei abhängig machen, die vor der Zahlung entstanden ist.
Umgekehrt wäre ein zweiter, eigener Renderweg für die Finalversion ein
Duplikat, das inhaltlich auseinanderdriften könnte, ohne dass es auffällt.

**Konsequenzen:** Das Wasserzeichen wird von mPDF in jeden Seiteninhalt
eingebrannt und ist keine optionale Ebene. Die Finalversion wird nach
bestätigter Zahlung vollständig neu aus dem gesperrten Calculation Snapshot
erzeugt und in ein eigenes Artefaktverzeichnis geschrieben. Eine gelöschte oder
nie erzeugte Vorschau verhindert die Finalversion nicht. Nachweise:
`Feature/Pdf/WatermarkTest` (Wasserzeichen je Seite, Finalversion frei davon,
keine entfernbare Ebene) und `Feature/Pdf/FinalIsRegeneratedTest`, insbesondere
`::test_kein_renderweg_nimmt_ein_bestehendes_pdf_entgegen` und
`::test_geloeschte_vorschau_verhindert_die_finalversion_nicht`.

---

### ADR-014: Heizkostenfall B wird manuell erfasst, nicht selbst gerechnet

**Entscheidung:** Für eine Zentralheizung ohne externen Abrechner setzt die
Plattform **keine** eigene Berechnung nach Heizkostenverordnung um. Der
Vermieter ermittelt die Beträge je Einheit selbst, außerhalb der Plattform, und
trägt nur die Ergebnisbeträge ein: Heizung, Warmwasser, CO2-Anteil des
Vermieters, CO2-Anteil des Mieters und sonstige Kosten des Heizbetriebs, dazu
die Herkunft der Berechnung als Freitext.

**Begründung:** Eine selbst gerechnete Verteilung nach Grund- und
Verbrauchskosten samt CO2-Stufenmodell wäre eine fachliche Aussage über die
Angemessenheit der Umlage. Sie träfe eine Bewertung, für die die Plattform
weder die Messdaten noch die Verantwortung hat, und sie stünde im Widerspruch
zum Grundsatz, dass fehlende oder unsichere Werte nicht geschätzt werden. Der
Auftraggeber hat diesen Leistungsumfang deshalb ausdrücklich begrenzt.

**Konsequenzen:**

- Die Beträge werden unverändert als Direktzuordnung je Einheit übernommen und
  bei einem Mieterwechsel innerhalb der Einheit zeitanteilig nach Nutzungstagen
  verteilt. Die Plattform rechnet die Verteilung selbst nicht nach.
- An drei Stellen steht ein sachlicher Hinweis: in der Eingabemaske, als
  Vermerk in der Mieter-PDF und im internen Blatt der Eigentümerübersicht
  zusammen mit der erfassten Herkunft. Alle drei Textbausteine sind als
  anwaltlich freizugeben gekennzeichnet und nennen keine Paragrafen.
- Liegt zusätzlich eine externe Abrechnung oder eine WEG-Summenposition für
  dieselbe Einheit und denselben Zeitraum vor, wird **nicht** addiert. Es
  entsteht eine Prüfaufgabe, und der Nutzer entscheidet über die Quelle.
- Eine Prüfsumme gegen einen optional erfassten Gesamtbetrag blockiert die
  Finalisierung bei einer Abweichung über der Toleranz. Ohne Gesamtbetrag
  entfällt die Gegenprobe, und darauf wird hingewiesen.
- `HeizkostenVCalculator` bleibt als Vollständigkeitsprüfung bestehen, ist aber
  ausdrücklich als **nicht vorgesehen** gekennzeichnet, nicht als „noch nicht
  freigeschaltet“. Der irreführende Ausnahmepfad, der eine spätere Freischaltung
  suggerierte, ist entfernt.
- Die Beträge werden bewusst **nicht** in das Folgejahr übernommen. Abschnitt
  8.3 verlangt, dass Heizkosten für das neue Jahr erneut erfasst und bestätigt
  werden.

### ADR-015: Zweitfaktor selbst implementiert, ohne neue Abhängigkeit

**Entscheidung:** TOTP nach RFC 6238 ist als eigene, framework-freie Klasse in
`app/Domain/Security/` umgesetzt, samt eigener Base32-Kodierung.

**Begründung:** Der Zweitfaktor ist für Adminrollen verpflichtend, und ohne ihn
war der Adminbereich in der Produktionsumgebung dauerhaft gesperrt, also ein
Betriebsblocker. Der Umfang ist klein und vollständig durch die offiziellen
Testvektoren des Standards prüfbar, weshalb eine zusätzliche Abhängigkeit
keinen Gewinn gebracht hätte.

**Konsequenz:** Die Implementierung wird gegen alle sechs SHA1-Testvektoren aus
RFC 6238, Anhang B, geprüft, nicht nur gegen sich selbst. Das Geheimnis liegt
anwendungsseitig verschlüsselt, die acht Wiederherstellungscodes einzeln
gehasht; ein verbrauchter Code ist entwertet. Ein QR-Bild wird bewusst nicht
erzeugt, weil es eine weitere Abhängigkeit erfordern würde; die otpauth-URI und
der abtippbare Schlüssel genügen.

### ADR-016: Korrektur nach Zahlung ist zum Start nicht verfügbar

**Kontext.** Der Masterprompt sieht eine Korrektur einer bezahlten Abrechnung vor,
kostenfrei innerhalb einer Frist, danach gegen Entgelt. Im Code waren
`CorrectionPriceRule`, `RecordCorrection` und `FinalizeBillingRun::markReplaced`
vorbereitet, aber ohne Einstieg: keine Route, kein Statusübergang aus
`FINALIZED`, keine Neuberechnung. Die Prüfung vom 04.09.2026 (Befunde B16 und
B35) hat gezeigt, dass die Konfiguration `PRICE_CORRECTION_FREE_DAYS` damit eine
Zusage darstellte, die technisch nicht eingelöst wurde.

**Entscheidung.** Die Korrektur nach Zahlung wird für den Start nicht umgesetzt.
Korrekturen erfolgen über einen neuen Abrechnungslauf zum regulären Preis. Die
Funktion ist überall ehrlich als nicht verfügbar gekennzeichnet: kein
Livegang-Blocker zur Korrekturfrist, Hinweis im Downloadbereich, Kommentar in
`.env.example`. Die vorbereiteten Klassen bleiben als Grundlage einer späteren
Umsetzung im Code.

**Alternativen.** Vollständige Umsetzung mit Statusübergang `FINALIZED` nach
Korrektur, Snapshot-Neuversion, Preisentscheid und zweiter Finalisierung. Abgelehnt
für den Start wegen des Umfangs (mehrere Tage) und der Wechselwirkung mit
Rechnungsstellung und Stornologik.

**Konsequenzen.** Ob und wann die Funktion kommt, entscheidet die
Geschäftsführung. Bis dahin darf keine Website- oder Vertragsformulierung eine
kostenfreie Korrekturfrist zusagen.

### ADR-017: XLSX wird zum Start nicht ausgewertet

**Kontext.** Der Uploaddialog nahm `.xlsx` an, die Auswertung scheiterte
anschließend, weil die Provider Tabellen nicht verarbeiten und keine serverseitige
Umwandlung existiert (Befund B60).

**Entscheidung.** XLSX wird bereits bei der Uploadanfrage mit klarer
Handlungsanweisung abgelehnt (CSV oder PDF). Eine serverseitige Umwandlung ist
nicht Teil des Startumfangs.

**Konsequenzen.** Der Hinweistext im Uploaddialog und das `accept`-Attribut nennen
XLSX nicht mehr. Eine spätere Umwandlung je Tabellenblatt in Text bleibt möglich,
ohne die Uploadkette zu ändern.

### ADR-018: Anwendungszeitzone Europe/Berlin

**Kontext.** Die Anwendung lief fest in UTC. Rechnungsdatum, Nummernkreisjahr und
der Tageswechsel des KI-Budgets wurden in UTC bestimmt; eine Zahlung am
01.01. um 00:30 Uhr deutscher Zeit erhielt eine Rechnung des Vorjahres
(Befunde B41, B64, B74).

**Entscheidung.** `config/app.php` setzt die Zeitzone auf `APP_TIMEZONE` mit
Standard `Europe/Berlin`. Rechnungsdatum, Nummernkreisjahr und Tagesgrenzen
werden in dieser Zeitzone gebildet.

**Konsequenzen.** Eloquent speichert Zeitstempel in Ortszeit. Vor dem Livegang
wird die Produktionsdatenbank frisch aufgesetzt; bestehende Staging-Datenbanken
zeigen bis dahin um ein bis zwei Stunden verschobene Zeitstempel.


## 4. Ordnerstruktur

Ist-Zustand nach Abschluss der Phasen 0 bis 5.

```
app/
  Application/        Use Cases, je Fachgebiet ein Ordner
    Account/          Mandantenkontext, Kontodaten, Revisionsprotokoll
    Admin/            Livegang-Blocker, Healthcheck, Kennzahlen, Datenschutzmonitor,
                      Nutzerverwaltung, Supportzugriff, Preise, Verarbeitung
    BillingRun/       State Machine und Portalstatus
    Calculation/      Eingabeaufbereitung, Lauf der Engine, Snapshot
    Documents/        Upload zusammensetzen, Archive entpacken, Löschlauf
    FollowUpYear/     Folgejahresübernahme
    Payment/          Checkout, Stripe-Ereignisse, Finalisierung, Rechnung
    Privacy/          DSGVO-Export, Kontolöschung, Aufbewahrung
    Reconciliation/   Hausgeld, Grundsteuer, Heizkosten, Dubletten
    Reminder/         Termine, Versand, Abmeldung
    Review/           Prüfoberfläche auf strukturierten Daten
    Wizard/           Schrittrahmen, Fortschritt, Vorschau, Bestätigung
  Domain/             reine Berechnungslogik, ohne Framework und ohne Eloquent
    Allocation/       Verteilerschlüssel
    Calculation/      Engine, DTOs, Ergebnisobjekte, Heizkosten, Rundung, WEG
    Money/            Geld als Integer-Cent-Value-Object
    Period/           taggenaue Zeiträume, Schaltjahr, Schnittmengen
    Support/          Engineversion, deutsche Zahlformatierung
  Enums/              persistierte Statuswerte
  Http/
    Controllers/Site/     öffentliches Frontend
    Controllers/Portal/   die Anwendung, mit Upload, Review, Wizard, Checkout, Download
    Controllers/Admin/    interner Bereich
    Controllers/Webhook/  Stripe
    Middleware/           Mandantenkontext, Security Header, HTTPS, Admin-Zweitfaktor
    Requests/             deutsche Formularvalidierung je Bereich
  Jobs/               Teilschritte der Dokumentpipeline, datenbankgestützt
  Listeners/ Mail/ Notifications/   Transaktionsmails und Zustellprotokoll
  Models/             Eloquent, mandantenbezogen, alle mit fillable oder guarded
  Policies/           Object-Level-Autorisierung
  Rules/              versionierte Prüfregeln (Context, Definitions, Engine)
  Services/
    Ai/               Providerabstraktion, Schemata, Prompts, Kosten, Integration
    Payment/          Stripe-Gateway, Signaturprüfung
    Pdf/              Engine, Renderer, Templatesichten, Ablage, Wasserzeichen
    Queue/            Lease, Heartbeat, Backoff, Dead Letter, Payload-Sperre
    Storage/          Kurzzeitbereich, Artefaktablage, Malwarescanner, Löschpfade
  Console/Commands/   Cron-Einstiege, darunter Privacy-Befehle
config/
  ai.php              Provider, Schwellenwerte, Löschfristen, Kostenbasis
  smartabrechnen.php  Betreiber, Preise, Uploads, Aufbewahrung, Toleranzen
database/
  migrations/         vorwärtskompatibel, rollbackfähig
  factories/          Testdaten, ausschließlich Platzhalterwerte
docs/
  abnahme.md          Ergebnis der unabhängigen Abschlussprüfung
  adr/                ergänzende Entscheidungsdokumente
  betrieb/            Backup und Restore, Löschkonzept im Betrieb
resources/views/
  site/ legal/        öffentliches Frontend und Rechtstexte
  portal/ admin/      Anwendung und interner Bereich
  pdf/                Abrechnungs- und Rechnungstemplates
  emails/             Transaktionsmails in HTML und Text
  auth/               Registrierung, Anmeldung, Bestätigung, Passwort
  layouts/ components/
routes/
  web.php             öffentliches Frontend, Rechtstexte, Bereichseinstieg
  portal.php admin.php auth.php console.php
storage/app/temporary-uploads/   Kurzzeitbereich, aus Backups ausgeschlossen
tests/
  Unit/Domain/        Berechnungslogik, Verteilerschlüssel, Zeiträume, Referenzen
  Unit/               Ai, Rules, Storage, Queue, Pdf, Payment, Privacy, Admin
  Feature/            HTTP, Autorisierung, Upload, Löschung, Zahlung, Admin, Mail
  Feature/EndToEnd/   Happy Path, Abbruch- und Fehlerwege, blockierte Rechnung
  Fixtures/           handprüfbare Referenzbeispiele und anonymisierte KI-Antworten
.github/workflows/    ci.yml (Pint, PHPStan, Tests auf MariaDB 10.11 und 11.4),
                      deploy.yml (Releasepaket, SFTP-Auslieferung)
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

**Verschlüsselung des Kurzzeitbereichs.** Jede Datei unter
`temporary_uploads`, also jeder Chunk, die zusammengesetzte Datei, die
HEIC-Konvertierung und jeder entpackte Archiveintrag, liegt auf der Platte
ausschließlich verschlüsselt. Verfahren: libsodium `secretstream`
(XChaCha20-Poly1305) in Blöcken von 1 MiB, damit auch 25-MB-Dateien mit
konstantem Speicher verarbeitet werden; fehlt `ext-sodium`, greift Laravels
`Encrypter` mit AES-256-GCM blockweise (`app/Services/Storage/Crypto`). Beide
Verfahren sind authentifiziert; ein manipuliertes, abgeschnittenes oder
verlängertes Chiffrat wird beim Lesen als Fehler abgelehnt, nicht als Inhalt
weitergegeben. Es gibt keinen Schalter, der die Verschlüsselung abschaltet,
auch nicht für Tests. Schlüsselableitung: Je Upload wird ein zufälliger
Dateischlüssel erzeugt und mit einem per HKDF-SHA-256 aus `APP_KEY`
abgeleiteten Hauptschlüssel (Zweck `smart-abrechnen:temporary-upload-v1`)
umhüllt in `temporary_uploads.encryption_key_wrapped` gespeichert; der
Klartextschlüssel liegt nie auf der Platte und wird mit der Löschung entfernt.
Ein Wechsel von `APP_KEY` macht laufende Uploads unlesbar; sie scheitern mit
klarem Fehler, ihre Chiffrate werden über Löschpfad und TTL-Cleanup entfernt,
der Nutzer lädt erneut hoch. Der HMAC-Fingerabdruck wird über den Klartext
gebildet, nicht über das Chiffrat. Alle Prüfungen (Magic Bytes, Struktur,
Seitenzahl, Malware, Fingerabdruck) und die Übergabe an den KI-Provider lesen
den entschlüsselten Klartextstrom im Arbeitsspeicher. Einzige Ausnahme ist
`ZipArchive`, das nur Dateipfade akzeptiert: `ArchiveGuard::inspectSource()`,
`PageCounter` für XLSX und `ExpandArchive` erhalten über
`TemporaryUploadStorage::withDecryptedCopy()` eine Klartextkopie unter
`<praefix>/arbeit/`, die unmittelbar nach dem Aufruf mit Nullbytes
überschrieben und gelöscht wird. Der TTL-Cleanup entfernt zusätzlich verwaiste
Verzeichnisse ohne Datensatz, sobald sie älter als 120 Minuten sind.

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

Die Spalte Nachweis nennt die Testklassen, die die Gegenmaßnahme belegen. Sie
wurden am 01.09.2026 im Rahmen der Abschlussprüfung gegen den Code abgeglichen,
siehe [docs/abnahme.md](docs/abnahme.md).

| Nr. | Bedrohung | Gegenmaßnahme | Nachweis |
| --- | --- | --- | --- |
| T1 | Fremdzugriff auf Abrechnungen anderer Mandanten | jede Query nach User/Organization gescoped, Middleware `EnsureOrganizationContext` prüft die Mitgliedschaft zusätzlich objektbezogen, Policy und Object-Level-Check in jeder Route und jedem Use Case, ULID statt fortlaufender ID | `Feature/AuthorizationScopeTest`, `Feature/Portal/TenantIsolationTest`, `Feature/Review/ReviewTenantIsolationTest`, `Feature/Payment/PaymentTenantIsolationTest` |
| T2 | Erraten von Download-URLs | Auslieferung nur über authentifizierte Streaming-Routen oder kurzlebige signierte Links, Ownership-Check je Abruf, Antwort 404 statt 403 gegen Existenzverrat | `Feature/Upload/DownloadTest`, `Feature/Mail/SignierterDownloadlinkTest`, `Feature/Payment/PaymentTenantIsolationTest::test_ein_fremdes_finales_dokument_ist_nicht_abrufbar` |
| T3 | Prompt Injection aus einem Dokument | Sicherheitsbaustein in jedem Systemprompt, zentral in `AbstractSystemPrompt::build()` erzwungen und mit Mindesttext gegen eine leere Konfiguration gesichert, Dokumentinhalt gilt als untrusted data, Ausgabe nur gegen strenges JSON-Schema, keine Toolausführung aus Dokumentinhalt | `Unit/Ai/PromptInjectionTest::test_sicherheitsbaustein_geht_mit_jedem_dokument_an_den_provider`, `Unit/Ai/PromptRegistryTest::test_jeder_systemprompt_enthaelt_den_sicherheitsbaustein` und `::test_leerer_sicherheitsbaustein_wird_durch_mindesttext_ersetzt` |
| T4 | Schadsoftware im Upload | MIME- und Magic-Byte-Prüfung, Größenlimits, Malware-Adapter, kein Ausführen von Uploads, Archive gegen Traversal und Zip-Bomben geschützt | `Unit/Storage/MimeGuardTest`, `Unit/Storage/ArchiveGuardTest`, `Unit/Storage/MalwareScannerTest`, `Feature/Upload/UploadLimitsTest` |
| T5 | Originaldaten überleben die Verarbeitung | getrennte Disk ohne Backup, `ArtifactType` kennt bewusst keinen Typ für Originale und `ArtifactStorage` prüft zusätzlich die Magic Bytes, Löschung nach Extraktion und bei endgültigem Fehler, unabhängiger TTL-Cleanup mit harter Grenze von 120 Minuten, Löschprotokoll, Datenschutzmonitor im Adminbereich | `Feature/Deletion/SourceDeletionTest`, `Feature/Deletion/TtlCleanupTest`, `Feature/Deletion/RetryFailedDeletionsTest`, `Unit/Storage/ArtifactStorageTest`, `Feature/EndToEnd/HappyPathTest` |
| T6 | Personenbezogene Daten in Logs, Queue oder Monitoring | Request- und Response-Bodies nie dauerhaft, `RedactingLogger` redigiert sensible Felder, `JobPayloadGuard` weist jeden Payload ab, der mehr als Referenz-IDs und kurze technische Parameter enthält, gekürzte IP-Adressen | `Feature/Deletion/NoOriginalLeakTest` (Artefakt-Disk, Queue-Payload, Logs, gesamte Datenbank), `Unit/Queue/JobPayloadGuardTest`, `Unit/Ai/RedactingLoggerTest`, `Feature/Mail/MailVersandProtokollTest::test_protokoll_enthaelt_keinen_vertraulichen_inhalt` |
| T7 | Freischaltung ohne Zahlung | Finalisierung nur nach signaturgeprüftem Webhook, Betrag, Währung und BillingRun werden verglichen, Event-ID unique, idempotente Verarbeitung, Browser-Redirect ohne Wirkung | `Feature/Payment/StripeWebhookTest`, darunter `::test_eine_falsche_signatur_wird_abgelehnt_und_schaltet_nichts_frei`, `::test_ein_abweichender_betrag_schaltet_nicht_frei`, `::test_eine_doppelt_zugestellte_meldung_wird_idempotent_verarbeitet`, `::test_der_browser_redirect_allein_schaltet_nicht_frei`; `Unit/Payment/StripeWebhookVerifierTest` |
| T8 | Doppelte oder lückenhafte Rechnungsnummern | atomare Vergabe in Transaktion mit Zeilensperre auf einer eigenen Zählertabelle (ADR-011), Unique-Constraint auf `invoices.number`, Storno nur über Stornorechnung | `Feature/Payment/InvoiceNumberSequenceTest`, darunter `::test_die_vergabe_sperrt_die_zaehlerzeile`, `::test_mehrere_vergaben_in_einer_transaktion_bleiben_lueckenlos`, `::test_eine_dublette_wird_durch_den_eindeutigen_schluessel_ausgeschlossen` |
| T9 | Credential Stuffing und Brute Force | Argon2id, Rate Limits auf Login, Reset, Upload, KI und Download, sichere Sessions, Zweitfaktor für Admins verpflichtend | `Feature/Auth/LoginTest`, `Feature/Auth/PasswordResetTest`, `Feature/Auth/SecurityConfigurationTest`, `Feature/Admin/ZweitfaktorSperreTest` |
| T10 | Missbrauch des Supportzugangs | getrennte Admin-Rollen und Sessions, Zugriff nur mit Begründung, jeder Einblick erzeugt einen Audit-Eintrag | `Feature/Admin/SupportzugriffTest`, `Feature/Admin/AdminZugangTest`, `Feature/Admin/NutzerverwaltungTest` |
| T11 | Kostenexplosion durch KI-Aufrufe | Token- und Kostenprotokoll je Call, Nutzer-, Tages- und Lauflimits, kleines Modell für einfache Aufgaben, keine Wiederverarbeitung identischer Dateien, Adminwarnung | `Feature/Ai/CostLimitAndReleaseGateTest`, `Unit/Ai/CostControlTest`, `Feature/Ai/AiCallRecordingTest` |
| T12 | Datenexport an einen Provider ohne Freigabe | `AI_REQUIRE_ZERO_DATA_RETENTION=true` produktiv verbindlich, Provider bleibt blockiert solange `AI_DATA_RETENTION_APPROVED=false`, Fallback darf die Sperre nicht umgehen | `Unit/Ai/ProviderReleaseGateTest`, `Feature/Ai/CostLimitAndReleaseGateTest`, `Feature/Admin/LivegangBlockerTest`, `Feature/Ai/ProviderFileDeletionTest` |

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

### Trusted Proxies

Auf IONOS erreicht die Anfrage PHP über einen vorgeschalteten Proxy
beziehungsweise Load Balancer. Schema, Host, Port und Client-IP stehen dann nur
in den `X-Forwarded-*`-Headern. Ohne Vertrauenskonfiguration erkennt
`ForceHttps` kein HTTPS und leitet endlos um, die Ratenbegrenzung sieht überall
dieselbe Proxy-Adresse, das Audit-Log protokolliert die falsche Adresse und
signierte URLs entstehen mit falschem Schema.

- Konfiguration über `TRUSTED_PROXIES` (`config/deploy.php`), angewendet in
  `bootstrap/app.php` über `App\Application\Install\TrustedProxyConfiguration`.
- Leer bedeutet: keinem Proxy vertrauen (lokale Entwicklung, Testsuite).
- `*` vertraut allen Proxys. Auf IONOS Webhosting ist das die praktikable
  Einstellung, weil die Proxy-Adressen nicht veröffentlicht sind. Das Risiko
  gefälschter Header besteht nur, wenn ein Client PHP unter Umgehung der
  Plattform erreicht; auf IONOS Webhosting läuft jede Anfrage über den Proxy.
- Auf einem eigenen Server (Profil B) werden die konkreten Adressen oder
  CIDR-Bereiche des Proxys eingetragen.
- Ausgewertet werden `X-Forwarded-For`, `-Host`, `-Port` und `-Proto`; der
  RFC-7239-Header `Forwarded` wird nicht ausgewertet.

### Kanonische Domain

`www.<domain>` leitet dauerhaft (301) auf die Domain aus `APP_URL` um, mit
Erhalt von Pfad und Query. Erste Linie ist die Regel in `public/.htaccess`, die
Apache ohne PHP beantwortet; Rückfall ist
`App\Http\Middleware\RedirectToCanonicalHost`, die vor `ForceHttps` läuft.
Nichts ist hart codiert; andere Hostnamen (Staging, lokal) bleiben unberührt.

### Document Root und Releaselayout

Bevorzugt zeigt der Document Root im IONOS-Control-Center auf
`<root>/current/public`. Lässt er sich nicht dorthin legen, liegt die
Wurzel-`.htaccess` des Repositories im Document Root und schreibt jede Anfrage
intern nach `current/public/` (oder `public/`) um. Vorher sperrt sie `.env`,
`.git`, `shared/`, `releases/`, `storage/`, `vendor/`, `app/`, `bootstrap/`,
`config/`, `database/`, `routes/`, `tests/` und die Metadateien der
Projektwurzel mit 403. Die Sperren stehen vor der Umschreibung und sind in
`tests/Unit/Install/HtaccessTest.php` nachgewiesen; `bin/deploy-sftp.php`
prüft sie nach jedem Umschalten zusätzlich per HTTP.

Releaselayout unterhalb von `SFTP_DEPLOY_ROOT`: `shared/.env`,
`shared/storage/`, `releases/<name>/`, `current/`. Der Releasezeiger
`current` ist ein gewöhnliches Verzeichnis und wird ohne SSH über zwei atomare
SFTP-Umbenennungen umgeschaltet; ein Rollback ist dieselbe Operation in
Gegenrichtung. Migrationen und Caches laufen danach über den CLI-Cronjob
`smartabrechnen:install`. Details in
[docs/betrieb/installation.md](docs/betrieb/installation.md).

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

**Zweite bekannte Einschränkung:** Die Testsuite ist auf einen sequenziellen
Lauf ausgelegt. `Storage::fake`, die kompilierten Blade-Templates unter
`storage/framework/views` und der Anwendungslog liegen in gemeinsam genutzten
Verzeichnissen, deshalb erzeugen zwei gleichzeitig laufende Testprozesse
wechselnde Fehlbefunde, unter anderem in den Upload- und Löschtests und in
Zusicherungen auf gerenderten HTML-Inhalt. Das ist ein
Isolationsthema der Testumgebung, kein Produktfehler. Vor einer
Parallelisierung in der CI ist je Prozess ein eigenes Testverzeichnis zu
setzen.

**Bekannte Einschränkung der Entwicklungsumgebung:** In der Sandbox dieses
Projekts steht kein MariaDB-Server zur Verfügung. Lokale Tests laufen daher auf
SQLite in-memory. Migrationen sind deshalb treiberneutral zu schreiben;
MariaDB-spezifische Konstrukte werden mit einer Treiberweiche gekapselt. Der
verbindliche Nachweis gegen MariaDB 10.11 und 11.x erfolgt in GitHub Actions
mit einem MariaDB-Service. Ein grüner lokaler Lauf ist kein Ersatz dafür.

---

## 10. Umsetzungsphasen und Status

Stand 04.09.2026, nachgeprüft in der Abschlussprüfung vom 01.09.2026 und der Vollprüfung vom 04.09.2026.

| Phase | Inhalt | Status |
| --- | --- | --- |
| 0 | Architektur, ADRs, Datenfluss, Threat Model, Ordnerstruktur, ENV-Schema, Designsystem, IONOS-Annahmen | abgeschlossen |
| 1 | Konto und Mandanten, Datenmodell, Objekte und Mietverhältnisse, BillingRun-State-Machine, Chunk-Upload, Lösch-Lifecycle, DB-Jobs | abgeschlossen |
| 2 | Providerinterface, OpenAI, Anthropic, Schemata mit Quellenbezug, Hausgeld-, Grundsteuer-, Mietvertrags-, Vorjahres- und Heizkostenextraktion, Dubletten und Reconciliation, Prüfoberfläche | abgeschlossen; produktiver Betrieb bleibt bis zur Datenschutzfreigabe des Providers technisch blockiert |
| 3 | Domain-Engine, Regeln und Warnungen, Wizard, Schnellweg, Vollobjektweg, PDF mit Wasserzeichen und Eigentümerübersicht | abgeschlossen |
| 4 | Preislogik, Stripe Checkout und Webhooks, Finalisierung und ZIP, HVM-Rechnung, IONOS-SMTP und Templates, Folgejahresübernahme und Erinnerungen | abgeschlossen; SMTP und Stripe sind gegen Fakes und mit Signaturprüfung getestet, der Nachweis am echten Postfach und am echten Stripe-Konto steht aus |
| 5 | Adminbereich und Blocker, DSGVO-Export und Löschung, Backups und Restore-Test, GitHub Actions und SFTP-Deployment, Last-, Sicherheits- und E2E-Tests, Abnahme | abgeschlossen; der Übertragungsschritt in `deploy.yml` ist aktiviert und wartet auf die SFTP-Secrets des IONOS-Kontos |
| Prüfung | Vollprüfung vom 04.09.2026 mit 15 Suchagenten und dreifacher Gegenprüfung, Behebung in acht Arbeitspaketen, adversariale Nachprüfung durch 16 Prüfer, zweite Runde in fünf Paketen, Nachprüfung durch 10 Prüfer, dritte Runde in drei Paketen | abgeschlossen; 85 bestätigte und 23 nachverifizierte Befunde, 39 Folgepunkte und 14 Restpunkte behoben, drei Ausschlüsse per ADR-016 bis ADR-018 |

Die Berechnungsengine aus Phase 3 wurde bewusst früh gebaut, weil sie die
fachliche Grundlage ist und ohne Infrastruktur testbar bleibt.

Der Abschluss einer Phase bedeutet: der Code ist vorhanden, durch Tests belegt
und statisch geprüft. Er bedeutet nicht, dass die zugehörige externe
Schnittstelle produktiv freigegeben ist. Was dafür noch fehlt, steht
ausschließlich in der Blockerliste des Adminbereichs und in
[docs/abnahme.md](docs/abnahme.md).

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
| Gewerbemietverhältnisse | mittel | technisch gesperrt: Regel `GEWERBE_MIETVERHAELTNIS` ist ein nicht auflösbarer Blocker, Vorschau und Zahlung sind für Läufe mit Gewerbeeinheit nicht möglich (Befund B33). Gesonderte Gewerbeumsetzung offen |
| mPDF-Layouttreue bei sehr langen Tabellen | niedrig | Seitenumbruchtests je Template, konservatives CSS |
| mPDF verändert `mb_internal_encoding` global und lässt es auf Windows-1252 stehen | behoben | `PdfEngine` sichert und restauriert die mbstring-Einstellungen in einem `finally`; Regressionstest vorhanden. Ohne diese Sicherung wurden `.env`-Werte beim nächsten Bootvorgang doppelt kodiert. |
| Tests sind bei gleichzeitig laufenden Testprozessen nicht isoliert | niedrig | Gemeinsam genutzt werden `storage/framework/testing` (`Storage::fake`), `storage/framework/views` (kompilierte Templates) und der Anwendungslog. Der Standardlauf ist sequenziell und stabil. Vor einer Parallelisierung in der CI ist je Prozess ein eigenes Verzeichnis für Testdisks und View-Cache zu setzen. |
| CSP benötigt `unsafe-eval` für den Alpine-Standardbuild | mittel | **offen.** `SecurityHeaders` setzt `script-src` weiterhin mit `'unsafe-eval'` (Zeile 103). Umgang: Wechsel auf `@alpinejs/csp` und Umschreiben der `x-data`-Ausdrücke, danach `unsafe-eval` entfernen. Verantwortlich: technische Betreuung |
| Registrierung und Adressänderung verraten, ob eine E-Mail bereits ein Konto hat | niedrig | Registrierung und Adressänderung antworten identisch, bei belegter Adresse geht eine Hinweismail an die Bestandsadresse (Befund B68). Ein Restorakel über die Kontoseite bleibt, bis ein Pending-E-Mail-Verfahren (Wechsel erst nach Bestätigung der neuen Adresse) umgesetzt ist. Verantwortlich: technische Betreuung |
| Einwilligungen werden nicht in `legal_acceptances` protokolliert | **erledigt** | `StartCheckout` und `ReviewConfirmation` schreiben Textversion, Zweck und Zeitpunkt. Nachweis: `Feature/Payment/CheckoutTest::test_die_zustimmungen_landen_datensparsam_in_legal_acceptances` und `Feature/Wizard/PreviewStepTest::test_die_bestaetigung_wird_in_legal_acceptances_protokolliert` |
| Eurobeträge aus der Kostenprüfung wurden über `float` in Cent umgerechnet | **erledigt** | Verstoß gegen Grundsatz 8, in der Abschlussprüfung am 01.09.2026 festgestellt und behoben. `UpdateCostItemRequest::cent()` rechnet jetzt wie `StorePrepaymentsRequest` ausschließlich über `BigDecimal`. Nachweis: `Feature/Review/CostReviewTest::test_eurobetraege_werden_exakt_in_cent_umgerechnet` mit sechs Betragsvarianten |
| Der Übertragungsschritt des SFTP-Deployments ist aktiviert, aber ohne Zugangsdaten | mittel, blockiert den Livegang | `deploy.yml` liefert per `bin/deploy-sftp.php` aus, sobald die Secrets gesetzt sind. Ein abgebrochener Upload wird verworfen und ist kein Rollback-Ziel (Befund B54). Verantwortlich: Betreiber stellt Zugang |
| Personentage-Schlüssel bei nicht durchgehend vermieteten Einheiten | **erledigt** als Blocker | Ein Personentage-Schlüssel bricht mit Prüfaufgabe ab, wenn eine Einheit im Zeitraum nicht durchgehend vermietet ist, weil jede Personenannahme für den Leerstand eine Schätzung wäre. Soll Personentage auch bei Leerstand nutzbar sein, braucht der Leerstand ein eigenes Personenfeld (fachliche Festlegung, Geschäftsführung) |
| Direktzuordnung in Schritt 8 interpretiert Dezimaleingaben als Cent | **erledigt** | Werte werden über `EuroAmountInput` in Cent gewandelt und als Euro angezeigt (Runde 2, N6) |
| `DatabaseJobQueue::succeed()` und `fail()` prüfen den Lease-Inhaber nicht | niedrig | **offen.** Nach Lease-Ablauf könnte ein verspäteter Erstprozess den Job eines zweiten Prozesses abschließen. Der Heartbeat (Befund B51) verkleinert das Fenster. Umgang: Lease-Token beim Abschluss vergleichen. Verantwortlich: technische Betreuung |
| `ExpandArchive::nextSequenceNumber()` vergibt Nummern ohne Sperre | **erledigt** | Sperre und Nummernvergabe je Archiveintrag in einer Transaktion (Runde 2, N19) |
| Kein Notfallbefehl für den einzigen Administrator ohne Zweitfaktor | **erledigt** | `smartabrechnen:admin:reset-2fa` mit Begründung, Bestätigung und Audit; Verfahren (Rückruf, zweite Person, Ticket) in `docs/betrieb/installation.md` beschrieben (Runde 2, N22) |
| Offene Providerdateien werden in einer Spalte je Upload geführt | niedrig | **entschärft.** Seit Runde 3 (R10) fragt der Provider vor jedem Dateiupload den Beobachter, ob eine Providerdatei offen ist; ist das der Fall, wird nichts übertragen. Eine zweite Datei kann damit nur noch in einem Wettlauf zweier Prozesse entstehen. Eine eigene Tabelle je Provider und Datei-ID bleibt die saubere Zielstruktur. Verantwortlich: technische Betreuung |
| Providerlöschung mit Antwort 404 gilt als nicht bestätigt | niedrig | **Entscheidung offen.** Eine vom Provider bereits automatisch abgelaufene Datei antwortet mit 404; das Dokument bleibt bis zum Dead Letter blockiert. Zu entscheiden, ob 404 als bestätigte Löschung gilt (Datenschutz gegen Verfügbarkeit). Verantwortlich: Geschäftsführung |
| Wiederholungspuffer für fehlgeschlagene Mails enthält signierte Downloadlinks | niedrig | **Entscheidung offen.** Der vollständige Nachrichteninhalt liegt bis zu 24 Stunden verschlüsselt in `email_messages.retry_payload` (Runde 2, N20), eine bewusste Ausnahme von der Regel, keine Downloadlinks zu protokollieren. Verantwortlich: Geschäftsführung bestätigt oder verkürzt das Fenster |
| Zahlungseingang zu einem Lauf mit ungültiger Vorschau (VORSCHAU_UNGUELTIG) ist in der Anwendung nicht abschließbar | niedrig | **Entscheidung offen.** Der Eingang wird festgehalten und im Zahlungsnachlauf angezeigt; bis zur Erstattung im Stripe-Konto bleibt der Lauf für einen neuen Checkout gesperrt. Umgang: Aktion im Zahlungsnachlauf (Erstattung anstoßen oder kaufmännisch erledigen) mit Freigabevermerk. Verantwortlich: Geschäftsführung |
| Liegen gebliebene Webhook-Ereignisse werden angezeigt, nicht automatisch nachverarbeitet | niedrig | akzeptiert. EMPFANGEN-Ereignisse ohne Abschluss erhalten bei Wiederzustellung HTTP 500, der Anbieter stellt bis zu drei Tage erneut zu; ältere Fälle stehen im Zahlungsnachlauf (Runde 2, N36). Die gespeicherte Nutzlast ist datensparsam und nicht erneut signaturprüfbar |
| Der Nachweis „kein Dateiinhalt in der Datenbank“ prüft eine feste Tabellenliste | niedrig | **offen.** `NoOriginalLeakTest::test_kein_dateiinhalt_in_der_gesamten_datenbank` prüft fünf namentlich genannte Tabellen. Eine künftig ergänzte Tabelle fällt nicht automatisch in die Prüfung. Umgang: Tabellenliste aus dem Schema ableiten, sobald der Testlauf verbindlich gegen MariaDB fährt. Verantwortlich: technische Betreuung |
| Ausnahmemeldungen in Logeinträgen der Zahlungs- und Mailschicht | niedrig | akzeptiert. Protokolliert werden Referenz-IDs und `getMessage()` einer Ausnahme, nicht Dokumentinhalte. Die Dokumentpipeline schreibt keine Ausnahmetexte in den Log. `Feature/Deletion/NoOriginalLeakTest::test_kein_dateiinhalt_in_den_logs` belegt, dass der Verarbeitungsweg log-frei von Inhalten bleibt |

### 11.1 Bewusst offener Punkt: Überführung der Extraktionsdaten aus Mietvertrag, Vorjahresabrechnung, Mieterliste, Zahlungsübersicht und Zählerliste (Befund B32)

**Stand.** Die Dokumentarten `MIETVERTRAG`, `MIETVERTRAG_NACHTRAG`, `VORJAHRESABRECHNUNG`, `MIETER_EINHEITENLISTE`, `KONTOAUSZUG_ZAHLUNGSUEBERSICHT` und `ZAEHLERLISTE_ABLESEPROTOKOLL` werden klassifiziert und über die jeweiligen Schemata ausgewertet, die Ergebnisse landen in `extracted_fields`. Es gibt jedoch keinen Konsumenten, der diese Felder in Fachdaten überführt: Vorauszahlungen, Umlagevereinbarungen und Verteilerschlüssel aus dem Mietvertrag, Einheiten und Mieter aus der Mieterliste, Ist-Zahlungen aus der Zahlungsübersicht, `meter_devices` und `meter_readings` aus der Zählerliste sowie Vorjahreskategorien für die Regeln `PreviousYearDeviationRule` und `MissingPreviousYearCategoryRule` entstehen ausschließlich aus manueller Erfassung. `MIETER_EINHEITENLISTE` fließt nur als Anwesenheit der Dokumentart in die Wegempfehlung ein. Die Reconciliation verarbeitet Hausgeld, Grundsteuer, Heizkosten, Brennstoff sowie Rechnungen und Bescheide.

**Entscheidung.** Die Überführung wird für den Start bewusst nicht umgesetzt. Begründung: Die Berechnung stützt sich damit ausschließlich auf vom Nutzer erfasste und bestätigte Werte; es entsteht kein falscher Geldbetrag und keine Abhängigkeit von der Extraktionsqualität bei Verträgen und Kontoauszügen. Eine Übernahme als Vorschlag zur Bestätigung wäre fachlich richtig, verlangt aber je Dokumentart einen eigenen Überführungsdienst mit Konfliktbehandlung gegen die manuell erfassten Stammdaten und ist für den Start nicht leistbar.

**Folgen, die offen bleiben und ehrlich benannt werden müssen.**

1. Die Vorjahresregeln liefern für jeden Lauf `bestanden`, weil `RuleContextFactory::fromBillingRun()` an beiden Produktivaufrufstellen ohne `previousYearCategories` aufgerufen wird. Der Prüfbericht zeigt damit einen Vergleich als bestanden, der nicht stattgefunden hat. Umgang bis zur Umsetzung: die Formulierung in der Oberfläche und die Zusagen auf der öffentlichen Seite (`site/home.blade.php`, `site/ablauf.blade.php`, `site/faq.blade.php`) und in der Transaktionsmail `dokumentverarbeitung-abgeschlossen` sind vom zuständigen Arbeitspaket auf den tatsächlichen Stand zu bringen; im Uploadhinweis (`resources/views/portal/upload/index.blade.php`) ist zu ergänzen, dass diese Dokumentarten derzeit nur abgelegt, nicht in Fachdaten übernommen werden. Es gibt keine zentrale Textstelle in `app/` oder `lang/`, über die dieser Hinweis ohne Änderung der View gesetzt werden könnte; `DocumentType::label()` ist bewusst nicht geeignet, weil das Label auch in PDFs und E-Mails erscheint.
2. Für diese Dokumentarten werden Aufrufe des Analysemodells bezahlt und das Tagesbudget des Nutzers belastet, ohne dass ein Ergebnis verwendet wird. Zusätzlich werden personenbezogene Daten (Mieternamen, Mieten, Zahlungseingänge) in `extracted_fields` gespeichert, obwohl kein Verarbeitungszweck besteht. Zu entscheiden ist, ob die fünf Dokumentarten bis zur Umsetzung von der Extraktion ausgenommen werden (Klassifikation ja, Feldextraktion nein). Diese Entscheidung ist der Geschäftsführung vorgelegt und nicht im Code vorweggenommen.
3. Die Schlüsselpriorität Mietvertragsregelung vor Vorjahr (`AllocationKeyWorkspace::contractKeyType()`) kann produktiv nicht greifen, weil kein Codepfad `AllocationKeySource::MIETVERTRAG` schreibt.

Verantwortlich: Geschäftsführung für die Produktentscheidung, technische Betreuung für Textanpassungen und eine spätere Umsetzung. Keine Codeänderung an der Reconciliation in diesem Stand.

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
