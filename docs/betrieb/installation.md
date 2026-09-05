# Inbetriebnahme auf IONOS

Schritt-für-Schritt-Anleitung für den Betreiber, in der Reihenfolge der
Durchführung. Jeder Schritt nennt das erwartete Ergebnis. Zugangsdaten werden
ausschließlich vom Betreiber hinterlegt und erscheinen an keiner Stelle in
Ausgaben, Protokollen oder dieser Anleitung.

Platzhalter in dieser Anleitung:

| Platzhalter | Bedeutung | Wo zu finden |
| --- | --- | --- |
| `<php>` | absoluter Pfad des PHP-CLI-Binaries, zum Beispiel `/usr/bin/php8.3` | IONOS-Control-Center, PHP-Einstellungen beziehungsweise Cronjob-Assistent |
| `<root>` | absoluter Pfad des Auslieferungsverzeichnisses (`SFTP_DEPLOY_ROOT`), zum Beispiel `/homepages/12/d123456789/htdocs/smart-abrechnen` | IONOS-Control-Center, Webspace, SFTP-Zugang |
| `<domain>` | kanonische Domain ohne www, produktiv `smart-abrechnen.de` | DNS |

Keinen dieser Werte raten. Beide Pfade werden im IONOS-Konto abgelesen.

---

## Überblick der Verzeichnisse auf dem Server

```
<root>/
  .htaccess          Rückfalloption für den Document Root (aus dem Repository)
  shared/
    .env             Konfiguration, einmalig angelegt, gilt für alle Releases
    storage/         gemeinsamer Speicher (Logs, Kurzzeitbereich)
  releases/
    release-.../     hochgeladene Releases, die letzten drei bleiben erhalten
  current/           das aktive Release (Releasezeiger)
    public/          Document Root
    artisan          Einstieg für alle Cronjobs
```

`current` ist ein gewöhnliches Verzeichnis. Beim Umschalten wird das bisherige
`current` nach `releases/<name>` zurückbenannt und das neue Release nach
`current` umbenannt. Dadurch bleiben Document Root, Cronjob-Pfade und
`.htaccess` dauerhaft gleich. Ein Rollback ist dieselbe Operation in
Gegenrichtung.

---

## Schritt 1: IONOS-Tarif prüfen

Prüfen Sie im Control-Center:

- PHP 8.3 oder neuer ist auswählbar, für Web **und** für die Kommandozeile
  (Cronjobs).
- Eine MariaDB-Datenbank (10.11 oder 11.x) ist im Tarif enthalten. Steht nur
  MySQL bereit, ist das ein Blocker (ARCHITECTURE.md, ADR-003); dann MariaDB
  auf einem passenden IONOS-Server oder als externe verwaltete Datenbank
  bereitstellen.
- Cronjobs sind einrichtbar; notieren Sie das kleinste verfügbare Intervall.
- SFTP-Zugang ist vorhanden.

**Erwartetes Ergebnis:** Tarif, PHP-Version, MariaDB-Version und
Cron-Intervall sind dokumentiert (README, Abschnitt „Vor Livegang“).

## Schritt 2: PHP-Version setzen

Control-Center, PHP-Einstellungen: PHP 8.3 (oder neuer) für die Domain
aktivieren. Notieren Sie den CLI-Pfad `<php>`, den der Cronjob-Assistent
anzeigt.

Benötigte Erweiterungen: `pdo_mysql`, `mbstring`, `gd`, `intl`, `zip`,
`openssl`, `fileinfo`, `ctype`, `json`, `tokenizer`, `dom`. Der
Installationsbefehl in Schritt 9 prüft sie und benennt fehlende einzeln.

**Erwartetes Ergebnis:** `<php> -v` im Cronjob-Assistenten meldet 8.3 oder
neuer.

## Schritt 3: MariaDB anlegen

Control-Center, Datenbanken: neue Datenbank anlegen, Zeichensatz `utf8mb4`.
Notieren Sie Host, Port, Datenbankname, Benutzer und Passwort für die `.env`.

**Erwartetes Ergebnis:** Die Datenbank ist angelegt; die Angaben liegen für
Schritt 8 bereit. Das Passwort wird nirgends sonst notiert.

## Schritt 4: SFTP-Zugang

Control-Center, SFTP: Zugang anlegen oder vorhandenen verwenden. Notieren Sie
Host, Port, Benutzer, Passwort (oder legen Sie einen SSH-Schlüssel hinterlegt)
und den absoluten Pfad `<root>`.

Legen Sie per SFTP-Client die Verzeichnisse `<root>/shared` und
`<root>/releases` an. Legen Sie zusätzlich außerhalb des Document Roots den
Zielpfad für Ergebnisartefakte an (`SFTP_ROOT` in der `.env`, zum Beispiel
`<root>/../privat/smart-abrechnen-artefakte`).

**Erwartetes Ergebnis:** Verbindung mit dem SFTP-Client gelingt; `shared/`,
`releases/` und der Artefaktpfad existieren.

## Schritt 5: Postfach

Control-Center, E-Mail: Postfach `kontakt@smart-abrechnen.de` anlegen oder
Passwort bereitstellen. SMTP-Server `smtp.ionos.de`, Port 465 (SSL) oder 587
(STARTTLS). SPF, DKIM und DMARC für die Domain prüfen.

**Erwartetes Ergebnis:** Anmeldung am Postfach im Webmail gelingt.

## Schritt 6: DNS und TLS

- `A`/`AAAA`-Eintrag für `<domain>` auf das IONOS-Hosting.
- `www.<domain>` ebenfalls auf das Hosting (die Anwendung leitet dauerhaft mit
  301 auf `<domain>` um, sowohl über `public/.htaccess` als auch über eine
  Middleware).
- TLS-Zertifikat im Control-Center für `<domain>` und `www.<domain>`
  aktivieren.

**Erwartetes Ergebnis:** `https://<domain>` liefert die IONOS-Standardseite
oder eine Fehlerseite mit gültigem Zertifikat.

## Schritt 7: Document Root festlegen

Zwei Wege, einer ist zu wählen:

**Weg A, bevorzugt:** Control-Center, Domain, Zielverzeichnis auf
`<root>/current/public` setzen. Die Wurzel-`.htaccess` wird dann nie gelesen.

**Weg B, Rückfall:** Lässt sich der Document Root nicht unterhalb von
`public/` legen, zeigt er auf `<root>`. Legen Sie die Datei `.htaccess` aus
dem Repository nach `<root>/.htaccess`. Sie schreibt alle Anfragen intern nach
`current/public/` um und sperrt `.env`, `shared/`, `releases/`, `storage/`,
`vendor/`, `app/` und alle weiteren Verzeichnisse mit Code oder Daten. Der
Unit-Test `tests/Unit/Install/HtaccessTest.php` weist die Sperren nach, das
Deployskript prüft sie zusätzlich nach jedem Umschalten per HTTP.

Weg B gilt auch, wenn das Repository ohne Releaselayout direkt im Document Root
liegt; die Datei erkennt beide Layouts.

**Erwartetes Ergebnis:** Der Document Root ist dokumentiert. Nach Schritt 8
liefert `https://<domain>/.env` einen Fehler (403 oder 404), niemals Inhalt.

## Schritt 8: Repository ausrollen und `.env` befüllen

### 8.1 `.env` anlegen

Kopieren Sie `.env.example` lokal nach `.env` und befüllen Sie die Gruppen.
Jede Variable ist in `.env.example` kommentiert.

| Gruppe | Variablen | Hinweis |
| --- | --- | --- |
| Anwendung | `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<domain>`, `APP_KEY` | `APP_KEY` lokal mit `php artisan key:generate --show` erzeugen. Er darf danach nie wechseln. |
| Proxy | `TRUSTED_PROXIES=*` | Auf IONOS Webhosting verbindlich, Begründung in `config/deploy.php`. |
| Speicherpfad | `LARAVEL_STORAGE_PATH` | Nur setzen, wenn der SFTP-Zugang keine Symlinks erlaubt (das Deployskript meldet das): `<root>/shared/storage`. |
| Datenbank | `DB_CONNECTION=mariadb`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | aus Schritt 3 |
| Betriebsmodus | `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database` | Standard, für Profil A ohne Redis |
| Dateiablage | `FILESYSTEM_DISK=sftp`, `SFTP_HOST`, `SFTP_PORT`, `SFTP_USERNAME`, `SFTP_PASSWORD` oder `SFTP_PRIVATE_KEY_PATH`, `SFTP_ROOT` | aus Schritt 4, genau ein Authentifizierungsweg |
| E-Mail | `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME` (`smtps` für Port 465, `smtp` für Port 587), `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | aus Schritt 5 |
| KI | `AI_PRIMARY_PROVIDER`, `OPENAI_API_KEY` oder `ANTHROPIC_API_KEY`, `AI_DATA_RETENTION_APPROVED`, `AI_MAX_DAILY_COST_CENT_PER_USER` (leer = kein Limit), `AI_BIND_DOCUMENT_PIPELINE` (leer lassen) | Freigabe erst nach Auftragsverarbeitungsvertrag und Retention-Nachweis; `check-config` meldet ein Tageslimit ohne Kalkulationsbasis in `config/ai.php` |
| Zahlung | `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` | Webhook-Secret entsteht in Schritt 14 |
| Betreiber | `HVM_*`, `HVM_MASTERDATA_CONFIRMED` | nach Bestätigung durch die Geschäftsführung |
| Aufbewahrung | `EXTRACTED_DATA_RETENTION_DAYS`, `GENERATED_PDF_RETENTION_DAYS`, `PRICE_CORRECTION_FREE_DAYS`, `MALWARE_SCANNER_DRIVER` | dokumentierte Betreiberentscheidungen |

Laden Sie die Datei per SFTP nach `<root>/shared/.env`. Diese Datei ist die
einzige Quelle der Konfiguration; jedes Release erhält sie beim Ausrollen als
Verknüpfung oder Kopie. Änderungen immer in `shared/.env` vornehmen und
anschließend `smartabrechnen:install` erneut ausführen (Schritt 9), damit der
Konfigurationscache neu entsteht.

### 8.2 Release ausrollen

Vom Arbeitsplatz aus, ohne GitHub Actions:

```bash
composer install --no-dev --classmap-authoritative --optimize-autoloader
npm ci && npm run build

export SFTP_HOST=... SFTP_PORT=22 SFTP_USERNAME=... SFTP_PASSWORD=...
export SFTP_DEPLOY_ROOT=<root>
export SMOKE_TEST_URL=https://<domain>

php bin/deploy-sftp.php --source=. --dry-run   # zeigt nur die Dateiliste
php bin/deploy-sftp.php --source=.
```

Alternativ über GitHub Actions: Workflow „Deploy (SFTP)“, Zielumgebung
`staging` oder `production`, Aktion `deploy`. Voraussetzung sind die Secrets
`SFTP_HOST`, `SFTP_PORT`, `SFTP_USERNAME`, `SFTP_PASSWORD` oder
`SFTP_PRIVATE_KEY`, `SFTP_DEPLOY_ROOT`, `SMOKE_TEST_URL` je Environment.

Das Skript lädt in `releases/<name>`, verknüpft oder kopiert `shared/.env`,
prüft die Pflichtdateien, schaltet `current` um, führt den Smoke-Test aus und
schaltet bei Fehlschlag automatisch zurück. Beim ersten Deployment gibt es
noch kein Rollbackziel; der Smoke-Test schlägt fehl, solange Schritt 9 noch
nicht gelaufen ist (die Datenbank ist leer). Das ist beim Erstlauf erwartbar:
führen Sie Schritt 9 aus und starten Sie `smartabrechnen:check-config`.

**Erwartetes Ergebnis:** `current/` existiert, `current/.env` ist vorhanden,
das Skript meldet `Release aktiviert`.

## Schritt 9: `smartabrechnen:install`

Legen Sie im Control-Center einen Cronjob an und führen Sie ihn einmal manuell
aus („Jetzt ausführen“):

```
<php> <root>/current/artisan smartabrechnen:install --no-interaction
```

Der Befehl ist idempotent und läuft ohne Rückfrage. Er prüft PHP-Version,
Erweiterungen, Schreibrechte auf `storage/` und `bootstrap/cache` sowie
`APP_KEY`, legt die Speicherverzeichnisse an, führt Migrationen aus, spielt
die Kostenkategorien nur bei leerer Tabelle ein, erzeugt die Produktionscaches
(`config`, `route`, `view`, `event`) und gibt die Livegang-Blocker aus. Ein
öffentlicher Speicherlink wird bewusst nicht angelegt.

Der Cronjob bleibt angelegt und wird **nach jedem Deployment** einmal
ausgeführt. Er ersetzt die frei erreichbare `migrate.php`, die es nicht gibt.

**Erwartetes Ergebnis:**

```
  [OK] PHP-Version: PHP 8.3.x erfuellt die Mindestversion 8.3.0.
  [OK] PHP-Erweiterungen: Alle benoetigten Erweiterungen sind geladen: ...
  [OK] Schreibrecht ./storage: ... ist beschreibbar.
  [OK] Schreibrecht bootstrap/cache: ... ist beschreibbar.
  [OK] APP_KEY: APP_KEY ist gesetzt.
  [OK] Speicherverzeichnisse: ...
  [OK] Migrationen: auf aktuellem Stand.
  [OK] Kategorien: 39 eingespielt.
  [OK] Caches: config, route, view und event erzeugt.
Livegang-Blocker: ...
Die Inbetriebnahme ist abgeschlossen.
```

Endet der Lauf mit `FEHLER`, steht die Handlung in derselben Zeile. Exit-Code
ist dann ungleich null, nichts wurde verändert.

## Schritt 10: `smartabrechnen:admin:create`

```
<php> <root>/current/artisan smartabrechnen:admin:create --email=admin@<domain> --name="Vorname Nachname"
```

Legt das Konto mit bestätigter E-Mail-Adresse und Adminrolle an und zeigt das
Einmalpasswort **genau einmal** in der Cronjob-Ausgabe. Es wird nicht
protokolliert. Existiert die Adresse bereits, wird nur die Rolle gesetzt und
kein Passwort geändert.

**Erwartetes Ergebnis:** Ausgabe `Konto angelegt: ...` mit Einmalpasswort.
Übernehmen Sie das Passwort sofort in einen Passwortmanager und löschen Sie die
Cronjob-Ausgabe im Control-Center.

## Schritt 11: Zweitfaktor einrichten

Melden Sie sich unter `https://<domain>/anmelden` mit Adresse und
Einmalpasswort an. Die Anmeldung führt eine Adminkennung ohne Zweitfaktor
direkt auf `https://<domain>/zwei-faktor/einrichten`. Richten Sie die
Authenticator-App ein und sichern Sie die Wiederherstellungscodes. Setzen Sie
anschließend über „Passwort vergessen“ ein eigenes Passwort.

**Erwartetes Ergebnis:** `https://<domain>/admin` ist erreichbar und zeigt das
Dashboard mit den Livegang-Blockern.

### 11.1 Zweitfaktor-Notfall: `smartabrechnen:admin:reset-2fa`

Der Adminbereich kann den Zweitfaktor fremder Konten zurücksetzen, nie den
eigenen. Ist die einzige Adminkennung ausgesperrt (Telefon verloren, keine
Wiederherstellungscodes mehr), bleibt nur der Weg über einen Cronjob auf dem
Server:

```
<php> <root>/current/artisan smartabrechnen:admin:reset-2fa --email=admin@<domain> --grund="Telefon verloren, Wiederherstellungscodes nicht vorhanden, Ticket 4711" --bestaetigt-von="Vorname Nachname" --bestaetigt
```

Ablauf, verbindlich:

1. **Identitätsprüfung.** Die betroffene Person weist sich gegenüber einer
   zweiten Person aus dem Betrieb aus, etwa durch Rückruf unter der
   hinterlegten Telefonnummer oder persönlich. Eine E-Mail allein genügt
   nicht, weil das Postfach selbst kompromittiert sein kann.
2. **Vier-Augen-Prinzip.** Der Reset wird von einer zweiten Person freigegeben.
   Ihr Name wird über `--bestaetigt-von` angegeben und im Revisionsprotokoll
   festgehalten. `--grund` beschreibt Anlass und Ticket, mindestens 15 Zeichen.
3. **Ausführung.** Der Befehl setzt Geheimnis, Wiederherstellungscodes und das
   Merkmal „angemeldet bleiben“ zurück, beendet alle Sitzungen der Kennung und
   schreibt zwei Protokolleinträge (`account.two_factor_reset` und
   `admin.user.two_factor_reset` mit Begründung, Kanal `konsole` und
   bestätigender Person). Ohne `--bestaetigt` fragt der Befehl vor der
   Ausführung nach; im Cronjob ist die Option daher erforderlich.
4. **Neue Einrichtung.** Die betroffene Person meldet sich sofort mit Passwort
   an, wird direkt zur Einrichtung eines neuen Zweitfaktors geführt und sichert
   die neuen Wiederherstellungscodes. Bis dahin ist die Kennung nur durch das
   Passwort geschützt; der Zeitraum ist so kurz wie möglich zu halten.
5. **Nachbereitung.** Cronjob im Control-Center löschen, Vorgang im
   Betriebsprotokoll vermerken, Protokolleintrag unter `/admin/protokoll`
   prüfen.

Der Befehl wirkt nur auf Kennungen mit Adminrolle. Kundenkonten werden
weiterhin im Adminbereich unter Nutzer zurückgesetzt.

**Erwartetes Ergebnis:** Ausgabe `Der Zweitfaktor wurde zurueckgesetzt und der
Vorgang protokolliert.` Die nächste Anmeldung führt auf
`https://<domain>/zwei-faktor/einrichten`.

## Schritt 12: `smartabrechnen:check-config`

```
<php> <root>/current/artisan smartabrechnen:check-config
<php> <root>/current/artisan smartabrechnen:check-config --send-test-mail=ihre@adresse.de
```

Prüft tatsächlich: Datenbankverbindung und MariaDB-Version, SFTP mit Schreib-
und Löschprobe, SMTP-Handshake mit Anmeldung, Stripe-Schlüssel per lesendem
API-Aufruf, Webhook-Secret, KI-Provider (nur bei Datenschutzfreigabe),
Vite-Manifest, letzter Schedulerlauf, `TRUSTED_PROXIES`, `APP_DEBUG`,
`APP_ENV`, HTTPS in `APP_URL`, KI-Anbindung und KI-Tageslimit samt
Kalkulationsbasis. Es wird kein Geheimnis ausgegeben. Der Befehl prüft in
einem eigenen Prozess gegen die aktuelle `shared/.env`; der produktive
Konfigurationscache aus Schritt 9 bleibt dabei unverändert liegen und muss
danach nicht neu erzeugt werden.

**Erwartetes Ergebnis:** Tabelle mit Spalten Prüfung, Status, Ergebnis,
Handlung. Exit-Code 0, wenn keine Zeile `FEHLER` trägt. Beim ersten Lauf ist
die Zeile `Cronjob` noch `FEHLER`, solange Schritt 13 fehlt.

## Schritt 13: Cronjobs

Den Scheduler im kleinsten verfügbaren Intervall anlegen, bevorzugt jede
Minute:

```
<php> <root>/current/artisan schedule:run
```

Der Scheduler startet daraus, ohne weitere Cronjobs:

| Aufgabe | Intervall | Befehl |
| --- | --- | --- |
| Lebenszeichen für `check-config` | jede Minute | intern |
| Queue-Slice der Dokumentverarbeitung | jede Minute, ohne Überlappung | `smartabrechnen:queue-slice --max-time=45 --max-jobs=100` |
| TTL-Cleanup des Kurzzeitbereichs | alle `TEMP_CLEANUP_INTERVAL_MINUTES` Minuten | `smartabrechnen:cleanup-temporary-uploads` |
| Wiederholung fehlgeschlagener Löschungen | alle 15 Minuten | `smartabrechnen:retry-failed-deletions` |
| Erinnerungen für Folgejahre | täglich 07:00 Europe/Berlin | `smartabrechnen:send-reminders` |
| Endgültige Kontolöschungen | täglich 03:20 | `smartabrechnen:execute-account-deletions` |
| Aufbewahrungsfristen | täglich 03:40 | `smartabrechnen:enforce-retention` |

**Fünf-Minuten-Intervall:** Ist nur `*/5` möglich, laufen alle Einträge
weiterhin korrekt, weil der Scheduler fällige Aufgaben nachholt. Unterschiede:
Hochgeladene Dokumente werden bis zu fünf Minuten später verarbeitet, die
Oberfläche zeigt das ehrlich als Wartezeit an. Der TTL-Cleanup läuft dann in
Fünf-Minuten-Schritten; die 120-Minuten-Höchstdauer bleibt gewahrt. Der
Konfigurationscheck meldet den Scheduler erst nach 15 Minuten ohne Lauf als
Fehler. Stripe-Webhooks sind vom Intervall unabhängig und werden sofort per
HTTPS verarbeitet.

Zweiter Cronjob, deaktiviert anlegen und nach jedem Deployment einmal manuell
ausführen:

```
<php> <root>/current/artisan smartabrechnen:install --no-interaction
```

Dritter Cronjob, Datenbanksicherung nach
[backup-und-restore.md](backup-und-restore.md).

**Erwartetes Ergebnis:** `smartabrechnen:check-config` meldet nach spätestens
zwei Minuten `Cronjob: OK` mit Zeitstempel des letzten Laufs.


### 13.1 Hosting ohne Shell: Cronjob als Webadresse

Bietet der Tarif Cronjobs nur als Aufruf einer Webadresse (URL-Cronjob) und
keinen Shellzugang, übernimmt der Wartungsaufruf der Anwendung alle Befehle
dieses Kapitels. Er ist nur aktiv, wenn in der `.env` ein Schlüssel gesetzt ist:

```
CRON_TOKEN=<mindestens 32 zufällige Zeichen, zum Beispiel aus openssl rand -hex 32>
```

Danach stehen folgende Adressen bereit (Schlüssel jeweils als `token`):

| Aufgabe | Adresse | Wann |
| --- | --- | --- |
| Installation und Update | `https://smart-abrechnen.de/wartung/install?token=…` | einmal nach jedem Release |
| Konfigurationsprüfung | `https://smart-abrechnen.de/wartung/check-config?token=…` | nach Bedarf |
| Ersten Administrator anlegen | `https://smart-abrechnen.de/wartung/admin?token=…&email=ADRESSE&name=NAME` | einmal; das Einmalpasswort steht genau einmal in der Antwort |
| Scheduler | `https://smart-abrechnen.de/wartung/schedule?token=…` | als URL-Cronjob jede Minute |

Erlaubt der Hoster keinen Cronjob im Minutentakt (IONOS Webhosting: nur
wenige Läufe am Tag), ruft ein externer Cron-Dienst (zum Beispiel cron-job.org)
die Scheduler-Adresse jede Minute auf. Dafür gibt es einen zweiten,
eingeschränkten Schlüssel `CRON_SCHEDULE_TOKEN`, der ausschließlich
`/wartung/schedule` erlaubt; Installation und Adminanlage bleiben dem
`CRON_TOKEN` vorbehalten, der nie an Dritte geht.

Die Antwort ist reiner Text mit dem Ergebnis des Befehls. Fehlt der Schlüssel
in der `.env`, existiert die Adresse nach außen nicht (404). Ein falscher
Schlüssel wird abgewiesen (403) und protokolliert, mehr als zehn Aufrufe je
Minute und IP werden gebremst. Der Schlüssel ist ein Zugangsdatum: nur in der
`.env` und im Cronjob-Dialog hinterlegen, nicht per E-Mail versenden, bei
Verdacht auf Weitergabe austauschen. Die Installation kann länger als ein
normaler Seitenaufruf dauern; der Aufruf läuft serverseitig weiter, auch wenn
der Browser die Verbindung vorher beendet. Das Ergebnis lässt sich dann über
`check-config` prüfen.

## Schritt 14: Stripe-Webhook eintragen

Stripe-Dashboard, Entwickler, Webhooks: Endpunkt
`https://<domain>/webhooks/stripe` anlegen und die Ereignisse auswählen, die
`app/Application/Payment/HandleStripeEvent.php` verarbeitet:
`checkout.session.completed`, `checkout.session.async_payment_succeeded`,
`checkout.session.async_payment_failed`, `checkout.session.expired`,
`payment_intent.payment_failed`, `payment_intent.canceled`, `charge.refunded`,
`charge.dispute.created`. Das angezeigte Signing Secret (`whsec_...`) als
`STRIPE_WEBHOOK_SECRET` in `shared/.env` eintragen, danach Schritt 9 erneut
ausführen, damit der Konfigurationscache den Wert kennt.

**Erwartetes Ergebnis:** Testereignis aus dem Stripe-Dashboard wird mit Status
200 beantwortet; `check-config` meldet `Stripe-Webhook: OK`.

## Schritt 15: Staging-Abnahme

Staging ist eine eigene Umgebung mit eigener Domain, eigener Datenbank,
eigenem `shared/.env` (`APP_ENV=staging`, Stripe-Testschlüssel) und eigenem
`SFTP_DEPLOY_ROOT`. Dort werden Release, Migrationen, Zahlungsablauf im
Testmodus und die anonymisierten Musterabrechnungen (README, Abschnitt „Vor
Livegang“) abgenommen.

**Erwartetes Ergebnis:** Alle Livegang-Blocker im Adminbereich sind behoben
oder als bewusste Entscheidung dokumentiert; `check-config` endet ohne
`FEHLER`.

## Schritt 16: Umschalten auf Produktion

1. Produktions-`shared/.env` mit Live-Schlüsseln und `APP_ENV=production`.
2. Deployment nach Schritt 8.2 mit `SFTP_DEPLOY_ROOT` der Produktion.
3. Cronjob `smartabrechnen:install` einmal ausführen.
4. `smartabrechnen:check-config` ohne `FEHLER`.
5. Erster Restore-Test nach [backup-und-restore.md](backup-und-restore.md).

**Erwartetes Ergebnis:** `https://<domain>/up` liefert 200,
`https://www.<domain>/` leitet mit 301 auf `https://<domain>/`,
`https://<domain>/.env` liefert 403 oder 404.

## Rollback

Die letzten drei Releases bleiben unter `releases/` erhalten.

```bash
php bin/deploy-sftp.php --list                       # zeigt aktives und verfügbare Releases
php bin/deploy-sftp.php --rollback=release-20260901120000-abc1234
```

oder Workflow „Deploy (SFTP)“ mit Aktion `rollback` und dem Releasenamen.
Danach den Cronjob `smartabrechnen:install` erneut ausführen, damit die
Caches zum zurückgeschalteten Code passen. Datenbankmigrationen sind
vorwärtskompatibel gestaltet; ein Rollback des Codes erfordert in der Regel
kein Zurückrollen der Datenbank. Ist es doch nötig, `migrate:rollback --step=1`
über einen Cronjob ausführen, vorher Sicherung nach backup-und-restore.md.

**Erwartetes Ergebnis:** `--list` zeigt das gewünschte Release als aktiv,
`/up` liefert 200.

---

## Häufige Fehlerbilder

| Symptom | Ursache | Handlung |
| --- | --- | --- |
| Endlose Umleitung auf https | `TRUSTED_PROXIES` leer | `TRUSTED_PROXIES=*` in `shared/.env`, Schritt 9 wiederholen |
| Alle Nutzer werden gemeinsam gedrosselt, Audit-Log zeigt eine einzige IP | `TRUSTED_PROXIES` leer | wie oben |
| Signierte Downloadlinks ungültig | `APP_URL` ohne https oder Proxy nicht vertraut | `APP_URL=https://<domain>`, `TRUSTED_PROXIES=*` |
| Seite ohne Stylesheet | `public/build` fehlt im Paket | `npm run build` vor dem Deployment, `check-config` Zeile Assets |
| `/.env` liefert Inhalt | Document Root falsch und keine Wurzel-.htaccess | Schritt 7 |
| Dokumente bleiben in „wird verarbeitet“ | Cronjob läuft nicht | `check-config` Zeile Cronjob, Schritt 13 |
| Anmeldung landet auf 403 „kein Bereich zugeordnet“ | Adminkennung ohne Mandant vor dieser Version | aktuelles Release ausrollen; Adminkennungen werden zum Zweitfaktor beziehungsweise Adminbereich geführt |
