# Smart Abrechnen

KI-gestütztes Nebenkostenabrechnungsportal der Hausverwaltung Müller GmbH.

Private und gewerbliche Vermieter erstellen auf `smart-abrechnen.de` aus ihren
vorhandenen Unterlagen eine Betriebskostenabrechnung. Die Anwendung
klassifiziert die hochgeladenen Dokumente, extrahiert die Werte, prüft sie
gegeneinander, führt den Nutzer nur durch die offenen Prüfstellen und erzeugt
eine Vorschau mit Wasserzeichen. Erst nach erfolgreicher Zahlung entstehen
wasserzeichenfreie Final-PDFs.

Der Vermieter bleibt Absender und inhaltlich Verantwortlicher jeder
Betriebskostenabrechnung. Die Plattform ist ein Software-Werkzeug und leistet
keine Rechtsberatung im Einzelfall.

**Architektur und Entscheidungen:** siehe [ARCHITECTURE.md](ARCHITECTURE.md).
**Ergebnis der Abschlussprüfung:** siehe [docs/abnahme.md](docs/abnahme.md).
**Betrieb:** [Inbetriebnahme auf IONOS](docs/betrieb/installation.md),
[Backup und Restore](docs/betrieb/backup-und-restore.md),
[Löschkonzept im Betrieb](docs/betrieb/loeschkonzept-betrieb.md).

---

## Funktionsumfang

Alle Phasen des Entwicklungsauftrags sind umgesetzt. Der Livegang ist
ausschließlich durch Betreiberangaben blockiert, nicht durch offene
Entwicklungsarbeit.

| Phase | Funktionsumfang |
| --- | --- |
| 0 | Architektur, Architekturentscheidungen, Datenfluss und Löschkonzept, Threat Model, ENV-Schema, HVM-Designsystem, IONOS-Betriebsprofile |
| 1 | Registrierung mit E-Mail-Bestätigung, Mandanten und Policies, Objekte, Einheiten, Vermieter und Mietverhältnisse mit Zeitachse, Zustandsmaschine des Abrechnungslaufs, Chunk-Upload in den verschlüsselten Kurzzeitbereich, Lösch-Lifecycle mit TTL-Cleanup, datenbankgestützte Jobs mit Lease und Dead Letter |
| 2 | Providerinterface mit OpenAI- und Anthropic-Anbindung, versionierte Systemprompts, JSON-Schemata mit Quellenbezug, Extraktion von Hausgeldabrechnung, Grundsteuerbescheid, Mietvertrag, Vorjahr und Heizkostenabrechnung, Dublettenerkennung und Abgleich, Kosten- und Konfidenzkontrolle, Prüfoberfläche ausschließlich auf strukturierten Daten |
| 3 | Berechnungsengine ohne Framework-Abhängigkeit, Verteilerschlüssel nach Fläche, MEA, Einheiten, Personen, Personentagen und Verbrauch, taggenaue Zeitanteile, Heizkostenverordnung, Rundung nach größten Resten, versionierte Prüfregeln, geführter Ablauf über zehn Schritte, Vorschau-PDF mit serverseitigem Wasserzeichen auf jeder Seite, Eigentümerübersicht und Anlage nach § 35a EStG |
| 4 | Serverseitige Preislogik, Stripe Checkout, signaturgeprüfte Webhooks mit Idempotenz, Finalisierung aus dem gesperrten Berechnungsstand, ZIP-Paket, HVM-Rechnung mit lückenlosem Nummernkreis und Storno, Transaktionsmails über IONOS-SMTP mit Zustellprotokoll, Folgejahresübernahme, Erinnerungen zu Q1, Q2, Q3 und zum 1. Dezember mit signiertem Abmeldelink |
| 5 | Adminbereich mit Livegang-Blockern, Healthcheck, Kennzahlen, Datenschutzmonitor, Nutzerverwaltung, Supportzugriff mit Begründung und Audit, Zweitfaktorpflicht, DSGVO-Datenexport als ZIP, Kontolöschworkflow mit Frist, Backupmanifest und Restore-Prüfung, CI mit MariaDB 10.11 und 11.4, Releasepaket für die SFTP-Auslieferung, End-to-End-Tests für Happy Path sowie Abbruch- und Fehlerwege |

---

## Datenschutz-Kernversprechen

Hochgeladene Originaldateien werden **nicht dauerhaft gespeichert**. Sie liegen
nur für die technisch notwendige Dauer von Upload, Sicherheitsprüfung und
Extraktion in einem verschlüsselten, nicht gesicherten Kurzzeitbereich und
werden danach automatisch gelöscht, spätestens nach Ablauf der Kurzzeit-TTL
(Standard und Höchstwert 120 Minuten). Dauerhaft bleiben ausschließlich die
erforderlichen strukturierten Extraktionsdaten mit Quellenangabe.

Nutzer bewahren ihre Originalbelege selbst auf und halten sie für eine mögliche
Belegeinsicht bereit. Darauf weist die Anwendung im Uploaddialog und in den
erzeugten Abrechnungen hin.

---

## Technischer Stack

| Baustein | Version |
| --- | --- |
| PHP | 8.3 oder neuer |
| Laravel | 12.x |
| Datenbank | MariaDB 10.11 LTS oder 11.x, InnoDB, utf8mb4 |
| Frontend | Blade, Tailwind CSS 4, Alpine.js, Build mit Vite |
| PDF | mPDF |
| Zahlung | Stripe Checkout |
| E-Mail | SMTP über IONOS |
| Queue | datenbankgestützt, Cron-getrieben |

---

## Lokale Einrichtung

Voraussetzungen: PHP 8.3+ mit den Erweiterungen `pdo_mysql`, `mbstring`, `gd`,
`intl`, `zip`, `openssl`, `fileinfo`, dazu Composer 2 und Node 20+.

```bash
composer install
cp .env.example .env
php artisan key:generate

# Datenbank in .env eintragen, dann
php artisan migrate --seed

npm install
npm run build

# Entwicklung: Server, Queue, Logs und Vite parallel
composer dev
```

Ohne MariaDB kann für Tests SQLite genutzt werden. Die Migrationen sind
treiberneutral geschrieben. Der verbindliche Nachweis gegen MariaDB 10.11 und
11.x erfolgt in der CI.

Für einen manuellen Browsertest mit Demodaten, SQLite und ohne echte
Zugangsdaten: siehe [docs/testanleitung.md](docs/testanleitung.md).

### Qualitätsprüfungen

```bash
composer lint    # Pint, nur prüfen
vendor/bin/pint  # Pint, formatieren
composer stan    # PHPStan Level 6 mit Larastan
composer test    # PHPUnit
composer check   # alles zusammen
```

Ausführlich, so wie in der Abschlussprüfung ausgeführt:

```bash
php artisan test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/pint --test
```

Stand 02.09.2026: **2.008 Tests mit 12.672 Assertions grün**, PHPStan Level 6
projektweit fehlerfrei, Pint sauber. Einzelne Nachweise lassen sich gezielt
laufen, zum Beispiel:

```bash
php artisan test --filter WatermarkTest        # Wasserzeichen auf jeder Seite
php artisan test --filter NoOriginalLeakTest   # keine Originaldaten in Disk, DB, Queue, Log
php artisan test --filter StripeWebhookTest    # nur ein geprüfter Webhook schaltet frei
php artisan test --filter InvoiceNumberSequenceTest  # lückenloser Nummernkreis
```

Die aktuelle Blockerliste des Adminbereichs lässt sich ohne Browser abfragen:

```bash
php artisan tinker --execute="foreach (app(App\Application\Admin\LaunchBlockerCheck::class)->report()->blockers as \$b) { echo \$b->severity.' | '.\$b->area.' | '.\$b->missing.PHP_EOL; }"
```

---

## ENV-Referenz

Die vollständige, kommentierte Referenz steht in
[`.env.example`](.env.example). Kurzüberblick der Gruppen:

| Gruppe | Variablen (Auszug) | Zweck |
| --- | --- | --- |
| Anwendung | `APP_KEY`, `APP_URL`, `APP_TIMEZONE` | Basis; `APP_URL` ist die kanonische Domain |
| Datenbank | `DB_CONNECTION=mariadb`, `DB_HOST`, `DB_DATABASE` | MariaDB 10.11 oder 11.x |
| Betriebsmodus | `SESSION_DRIVER`, `QUEUE_CONNECTION`, `CACHE_STORE` | datenbankgestützt, damit IONOS Profil A ohne Redis läuft |
| Dateiablage | `FILESYSTEM_DISK=sftp`, `SFTP_*`, `S3_*` | Speicher ausschließlich für erzeugte Ergebnisartefakte |
| E-Mail | `MAIL_*` | IONOS SMTP, Absender `kontakt@smart-abrechnen.de` |
| KI | `AI_PRIMARY_PROVIDER`, `AI_FALLBACK_*`, `AI_REQUIRE_ZERO_DATA_RETENTION`, `AI_DATA_RETENTION_APPROVED`, `OPENAI_*`, `ANTHROPIC_*`, `AI_CONFIDENCE_REVIEW_THRESHOLD`, `AI_MAX_DAILY_COST_CENT_PER_USER` | Provider, Modelle, Schwellenwerte, Kostenlimits |
| Zahlung | `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` | Stripe Checkout und Webhook |
| Preise | `PRICE_PER_STATEMENT_GROSS_CENT=2490`, `PRICE_BASE_GROSS_CENT=0`, `VAT_RATE_PERCENT=19` | Bruttopreis je erzeugter Mieterabrechnung |
| Betreiber | `HVM_*` | Pflichtangaben, Steuer- und Bankdaten |
| Erinnerungen | `REMINDER_Q1_DATE`, `REMINDER_Q2_DATE`, `REMINDER_Q3_DATE`, `REMINDER_DECEMBER_DATE` | Q1, Q2, Q3 und 1. Dezember, Zeitzone Europe/Berlin |
| Uploads und Löschung | `UPLOAD_MAX_FILE_MB`, `MALWARE_SCANNER_DRIVER`, `TEMP_UPLOAD_TTL_MINUTES`, `TEMP_CLEANUP_INTERVAL_MINUTES`, `AI_PROVIDER_FILE_TTL_MINUTES`, `SIGNED_DOWNLOAD_TTL_MINUTES` | Grenzen und Löschfristen |
| Monitoring | `LOG_LEVEL`, `SENTRY_DSN` | strukturierte Logs, optionales Error Monitoring |

Geheimnisse gehören ausschließlich in die `.env` des Zielsystems oder in den
Secret Store der CI, niemals in das Repository.

---

## Deployment auf IONOS

Ziel ist ein IONOS-Linux-Hosting oder ein IONOS-Server. Kanonische Produktiv-URL
ist `https://smart-abrechnen.de`; `www.smart-abrechnen.de` leitet dauerhaft
darauf um. Die Anwendung unterstützt zwei Betriebsprofile, Details in
[ARCHITECTURE.md](ARCHITECTURE.md), Abschnitt 8.

Die vollständige Schritt-für-Schritt-Anleitung für den Betreiber steht in
[docs/betrieb/installation.md](docs/betrieb/installation.md). Befehle der
Inbetriebnahme:

```bash
php artisan smartabrechnen:install --no-interaction   # Umgebung prüfen, Migrationen, Kategorien, Caches, Livegang-Blocker
php artisan smartabrechnen:admin:create --email=...   # erster Administrator mit Einmalpasswort, Zweitfaktor beim ersten Login
php artisan smartabrechnen:check-config               # Datenbank, SFTP, SMTP, Stripe, KI, Assets, Cronjob, Sicherheitseinstellungen
php bin/deploy-sftp.php --source=. [--dry-run]        # SFTP-Auslieferung vom Arbeitsplatz, Rollback mit --rollback=<release>
```

### Releasepaket

Die CI erzeugt ein reproduzierbares Paket:

```bash
composer install --no-dev --classmap-authoritative --optimize-autoloader
npm ci && npm run build
# Tests laufen vor der Paketierung
```

Im Paket enthalten: Anwendungscode, `vendor/`, gebaute Assets,
Healthcheck-Datei, Versionsmetadaten. Nicht enthalten: `.env`, Testdaten, Logs,
lokale Uploads.

### SFTP-Auslieferung

1. Upload in ein **neues** Releaseverzeichnis, niemals halb über die aktive
   Version schreiben.
2. Smoke-Test gegen Staging oder das neue Release.
3. Document Root beziehungsweise Releasezeiger erst danach umschalten.
4. Vorherige Version für Rollback erhalten.
5. Datenbankmigrationen vorwärtskompatibel halten, Wartungsmodus nur so kurz
   wie nötig.

Ohne SSH werden Migrationen über einen im IONOS-Control-Center eingerichteten,
gezielt aktivierten CLI-Cronjob ausgeführt. Es wird **keine** frei erreichbare
`migrate.php` gebaut.

### Cronjob

Den tatsächlichen PHP-Pfad und Document Root im IONOS-Konto ermitteln, nicht
raten:

```bash
/usr/bin/php8.3 /absoluter/pfad/zum/release/artisan schedule:run
```

Der Scheduler startet daraus kurze Queue-Läufe
(`queue:work --stop-when-empty --max-time=50`), den TTL-Cleanup des
Kurzzeitbereichs und die Erinnerungen. Stripe-Webhooks laufen unabhängig davon
sofort über HTTPS.

### Backups

- automatisierte MariaDB-Backups, verschlüsselt, mit getrennter Aufbewahrung
- Backup der erzeugten Ergebnisartefakte
- **technisch ausgeschlossen:** `storage/app/temporary-uploads`, Seitenbilder,
  vollständige OCR-Dateien, KI-Zwischendaten, Queue-Payloads
- dokumentierte Retention, regelmäßiger Restore-Test; der automatisierte
  Restore-Test weist zusätzlich nach, dass keine Original-Quelldateien
  wiederherstellbar sind

Beispiel für einen Datenbank-Dump als Cronjob:

```bash
mysqldump --single-transaction --quick --default-character-set=utf8mb4 \
  -h "$DB_HOST" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" \
  | gzip > /privater/pfad/backups/db-$(date +\%Y\%m\%d-\%H\%M).sql.gz
```

---

## Vor Livegang zwingend vom Betreiber bereitzustellen oder freizugeben

Fehlende Punkte werden im Adminbereich als Livegang-Blocker angezeigt. Keine
dieser Angaben wird erfunden.

### Tatsächlich erkannte Blocker, Stand 01.09.2026

Das ist die Ausgabe von `LaunchBlockerCheck` gegen die aktuelle Konfiguration,
nicht eine Abschrift der Vorgabe. Die Punkte darunter sind die vollständige
Checkliste, auch für das, was technisch nicht selbst erkennbar ist.

| Schwere | Bereich | Was fehlt | Verantwortlich |
| --- | --- | --- | --- |
| blockierend | Betreiber und Rechnung | bestätigte Steuernummer, Umsatzsteuer-Identifikationsnummer, IBAN und BIC; danach `HVM_MASTERDATA_CONFIRMED=true` | Geschäftsführung, in Abstimmung mit dem Steuerberater |
| blockierend | Zahlung | `STRIPE_KEY`, `STRIPE_SECRET` und `STRIPE_WEBHOOK_SECRET` sind nicht gesetzt | Betreiber über das Stripe-Konto |
| blockierend | KI-Provider | Datenschutzfreigabe fehlt für OpenAI und Anthropic; `AI_DATA_RETENTION_APPROVED` steht auf `false`, solange kein Nachweis über Zero Data Retention je Providerorganisation, Modell und Funktion vorliegt | Betreiber, mit Auftragsverarbeitungsvertrag und Retention-Dokumentation |
| blockierend | Uploads | `MALWARE_SCANNER_DRIVER=disabled`; zu entscheiden ist `clamav`, `external` oder eine schriftliche Risikobewertung | Betreiber |
| blockierend | Recht | Impressum, Datenschutzerklärung, AGB und Widerrufsbelehrung sind Platzhalterfassungen | Betreiber über die beauftragte Kanzlei |
| blockierend | Gestaltung | in `public/ci` fehlt `Logo_HVM.svg` oder `Logo_HVM.jpg` | Betreiber; es wird kein Logo erzeugt oder nachgezeichnet |
| Entscheidung | Preis | `PRICE_CORRECTION_FREE_DAYS` ist nicht gesetzt, es gilt der Standard von null Tagen kostenfreier Korrektur | Geschäftsführung, kaufmännische Entscheidung |

Nicht als Blocker erkennbar, weil erst am Zielsystem prüfbar, dafür im
Admin-Healthcheck sichtbar: MariaDB-Serverversion, Schreibbarkeit der
Artefaktablage, SFTP-Erreichbarkeit und SMTP-Versand. Ebenfalls nicht technisch
erkennbar sind die Aktivierung des Übertragungsschritts im SFTP-Deployment und
die Abnahme anonymisierter Musterabrechnungen.

### Hosting und Infrastruktur

- [ ] tatsächlicher IONOS-Tarif und verfügbare PHP-Version bestätigt
- [ ] MariaDB-Host, Version (10.11 LTS oder 11.x) und Zugangsdaten
- [ ] SFTP-Zugang und privater Zielpfad außerhalb des Webroots
- [ ] Domain, DNS-Eintrag und TLS für `smart-abrechnen.de`, Umleitung von `www`
- [ ] kleinstes verfügbares Cron-Intervall dokumentiert
- [ ] Backupziel, Retention und Verantwortlicher für den Restore-Test

### Konten, Keys und Verträge

- [ ] Passwort des IONOS-Postfachs `kontakt@smart-abrechnen.de`
- [ ] SPF, DKIM und DMARC für die Domain geprüft und dokumentiert
- [ ] OpenAI- und/oder Anthropic-API-Projekt mit eigenem API-Key und eigener
      API-Abrechnung (keine private Websession automatisieren)
- [ ] Auftragsverarbeitungsvertrag beziehungsweise DPA mit dem eingesetzten
      KI-Provider
- [ ] bestätigte Providerkonfiguration für kurzzeitige Verarbeitung,
      Dateilöschung und, soweit verfügbar, Zero Data Retention; erst dann
      `AI_DATA_RETENTION_APPROVED=true`
- [ ] Auftragsverarbeitungsverträge mit IONOS und Stripe
- [ ] Stripe-Konto, Secret Keys, Webhook Secret und Unternehmensdaten

### Recht und Kaufmännisches

- [ ] anwaltlich freigegebene AGB, Datenschutzerklärung, Widerrufsbelehrung und
      Impressum, dazu die Nutzerbestätigungstexte im Checkout
- [ ] bestätigte Betreiber-, Steuer- und Bankdaten (`HVM_TAX_ID`,
      `HVM_VAT_ID`, `HVM_IBAN`, `HVM_BIC`), danach
      `HVM_MASTERDATA_CONFIRMED=true`
- [ ] endgültiger Bruttopreis je Mieterabrechnung
- [ ] dokumentierte Aufbewahrungsfristen für strukturierte Extraktionsdaten und
      Ergebnis-PDFs; Kurzzeit-TTL für Originaluploads höchstens 120 Minuten
- [ ] Entscheidung zum Malware-Scanner (`clamav`, `external` oder bewusst
      `disabled` mit dokumentierter Risikobewertung)
- [ ] Entscheidung zur kostenfreien Korrekturfrist nach der Zahlung
      (`PRICE_CORRECTION_FREE_DAYS`); ohne Entscheidung gilt null Tage

### Gestaltung und Abnahme

- [ ] HVM-Logo und CI-Assets in `/public/ci/` eingespielt (siehe
      [public/ci/README.md](public/ci/README.md)); es wird kein Logo generiert
- [ ] Abnahme realer, vollständig anonymisierter Musterabrechnungen
- [ ] Secrets für `.github/workflows/deploy.yml` je Environment hinterlegt
      (`SFTP_HOST`, `SFTP_PORT`, `SFTP_USERNAME`, `SFTP_PASSWORD` oder
      `SFTP_PRIVATE_KEY`, `SFTP_DEPLOY_ROOT`, `SMOKE_TEST_URL`) und `shared/.env`
      auf dem Server angelegt, siehe [docs/betrieb/installation.md](docs/betrieb/installation.md)
- [ ] erster Restore-Test nach [docs/betrieb/backup-und-restore.md](docs/betrieb/backup-und-restore.md)
      durchgeführt und protokolliert

---

## Lizenz und Vertraulichkeit

Proprietär. Interne Informationen, Kundendaten und Zugangsdaten gehören nicht in
dieses Repository.
