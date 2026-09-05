# Testanleitung für den manuellen Browsertest

Stand 01.09.2026. Diese Anleitung führt Sie ohne MariaDB, ohne echte
Zugangsdaten und ohne Kosten durch einen vollständigen Browsertest der
Anwendung. Alle Befehle sind in dieser Reihenfolge auszuführen und in genau
dieser Fassung geprüft worden.

Die Demodaten sind vollständig frei erfunden. Sie enthalten keine
Bestandsdaten, keine echten Personen und keine echten Bankverbindungen.

---

## 1. Einrichtung ohne MariaDB

Voraussetzungen: PHP 8.3 oder neuer, Composer 2, Node 20 oder neuer.

```bash
# 1. Abhängigkeiten und Konfiguration
composer install
cp .env.example .env
php artisan key:generate

# 2. Die Werte aus Abschnitt 2 in der .env setzen, insbesondere
#    APP_ENV=local und DB_CONNECTION=sqlite

# 3. Leere SQLite-Datei anlegen
touch database/database.sqlite

# 4. Migrationen und produktiver Grundstock der Kostenarten
php artisan migrate --seed

# 5. Demodaten laden (nur lokal, niemals produktiv)
php artisan db:seed --class="Database\Seeders\DemoDataSeeder"

# 6. Oberfläche bauen
npm install
npm run build

# 7. Server starten
php artisan serve
```

Die Auswertung hochgeladener Unterlagen läuft über die Warteschlange, die
produktiv ein Cronjob antreibt. Öffnen Sie dafür ein zweites Fenster und
starten Sie den Zeitplaner, solange Sie testen:

```bash
php artisan schedule:work
```

Alternativ verarbeiten Sie einen einzelnen Durchgang von Hand, wenn eine
Unterlage in der Analyse hängt:

```bash
php artisan smartabrechnen:queue-slice --max-time=45 --max-jobs=100
```

Die Anwendung läuft danach auf `http://127.0.0.1:8000`. Schritt 5 gibt am Ende
eine Übersicht aller Konten, Objekte, Läufe und der passenden URLs aus. Diese
Ausgabe ist Ihr Wegweiser, die dort genannten Kennungen sind bei jedem
Neuaufbau andere.

Nur der Schritt `migrate --seed` läuft den produktiv nötigen
`CostCategorySeeder`. Der Demodaten-Seeder wird ausschließlich durch den
ausdrücklichen Aufruf in Schritt 5 gestartet und bricht in der Umgebung
`production` mit einer Meldung ab.

---

## 2. Umgebungsvariablen für den Test ohne echte Zugangsdaten

| Variable | Wert | Was damit simuliert wird | Was damit NICHT geprüft ist |
| --- | --- | --- | --- |
| `APP_ENV` | `local` | Nicht-Produktivbetrieb, der Testprovider und der Demodaten-Seeder sind überhaupt erst erlaubt. | Das Verhalten der Produktivumgebung, etwa erzwungenes HTTPS. |
| `APP_DEBUG` | `true` | Fehlerseiten mit Ursache und Datei, hilfreich für Ihre Fehlermeldung. | Die produktiv sichtbare, bewusst knappe Fehlerseite. |
| `APP_URL` | `http://127.0.0.1:8000` | Korrekte Links in Seiten und Mails. | Kanonische Domain und Weiterleitung von `www`. |
| `DB_CONNECTION` | `sqlite` | Datenbank als Datei, kein Datenbankserver nötig. | MariaDB-spezifisches Verhalten. Der Nachweis dafür läuft in der CI. |
| `DB_DATABASE` | absoluter Pfad zu `database/database.sqlite` | Ablage der Testdatenbank in einer Datei. | Nichts Weiteres. |
| `SESSION_ENCRYPT` | `false` | Lesbare Sitzung ohne zusätzliche Hürde bei `http`. | Verschlüsselte Sitzungsdaten wie produktiv. |
| `SESSION_SECURE_COOKIE` | `false` | Anmeldung funktioniert über `http` ohne TLS. | Cookie-Sicherheit produktiv unter TLS. |
| `AI_PRIMARY_PROVIDER` | `fake` | Der Testprovider antwortet aus hinterlegten Beispielantworten, ohne Netz und ohne Kosten. | Die echte Auswertung eines beliebigen PDF durch OpenAI oder Anthropic, Konfidenzen und Kostenzähler realer Aufrufe. |
| `AI_FALLBACK_ENABLED` | `false` | Kein zweiter Provider, eindeutiges Verhalten. | Umschaltung auf den Zweitprovider bei Störung. |
| `AI_BIND_DOCUMENT_PIPELINE` | `true` | Die Dokumentpipeline ist mit der KI-Schicht verdrahtet, Uploads werden also ausgewertet. | Nichts Weiteres. |
| `MAIL_MAILER` | `log` oder `array` | Mailversand wird nur protokolliert (`log`) beziehungsweise nur im Speicher gehalten (`array`). Bestätigungslinks lesen Sie in `storage/logs/laravel-JJJJ-MM-TT.log`. | Echter SMTP-Versand über IONOS, Zustellbarkeit, Bounces, Abmeldelinks im echten Postfach. |
| `FILESYSTEM_DISK` | `local` | Erzeugte Ergebnisdateien wie Vorschau-PDF liegen unter `storage/app`. | Ablage über SFTP, Rechte und Störungsverhalten des Zielspeichers. |
| `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` | leer lassen | Es entsteht kein Zahlungsvorgang und keine echte Buchung. | Der gesamte Checkout, die Rückleitung und der signaturgeprüfte Webhook. Siehe Abschnitt 6. |

Uploads und Originaldateien liegen in jedem Fall nur im verschlüsselten
Kurzzeitbereich und werden nach der Auswertung gelöscht. Das ist Teil des
Löschkonzepts und nicht durch die Testkonfiguration abgeschaltet.

---

## 3. Demodaten

Zugangsdaten aller drei Konten, Passwort jeweils `demo-passwort-2026`:

| Konto | E-Mail | Zweck |
| --- | --- | --- |
| Kunde | `demo@smart-abrechnen.test` | Hauptkonto, E-Mail bestätigt, enthält alle Objekte und Läufe |
| Adminrolle | `demo-admin@smart-abrechnen.test` | Zugang zum Adminbereich, Rolle ADMIN |
| Zweiter Kunde | `demo-zweitkunde@smart-abrechnen.test` | eigener Mandant für die Prüfung der Mandantentrennung |

Enthaltene Objekte und Läufe:

- Weg A, Schnellabrechnung: Eigentumswohnung `Lindenhofweg 12, Wohnung 7`, eine
  Einheit, ein Mietverhältnis über das ganze Jahr. Vorbereitet sind die
  ausgelesenen Inhaltsdaten einer WEG-Hausgeldabrechnung, eines
  Grundsteuerbescheids und einer externen Heizkostenabrechnung.
- Weg B, Vollobjekt: Mehrfamilienhaus `Buchenstrasse 40`, sechs Einheiten,
  Mieterwechsel zum 30.06./01.07.2025, Leerstandsmonat August 2025, zwölf
  Kostenarten, Verteilerschlüssel gesetzt, Vorauszahlungen erfasst.
- Lauf 1, Zeitraum 2024, Zustand ENTWURF: Einstieg beim Upload.
- Lauf 2, Zeitraum 2025, Zustand PRÜFUNG ERFORDERLICH: offene Prüfaufgaben in
  der Kostenprüfung, darin eine bewusste Dublette und eine bewusst nicht
  umlagefähige Position (Verwaltervergütung).
- Lauf 3, Zeitraum 2025, Zustand VORSCHAU BEREIT: Berechnung und Vorschau mit
  Wasserzeichen liegen vor.

Wichtig: Die Demodaten enthalten keine Originaldateien und keine temporären
Uploads. Es bestehen ausschließlich die strukturierten Extraktionsdaten mit
Quellenangabe, genau so wie der Zustand nach einer echten Auswertung aussieht.

---

## 4. Prüfpfad

Arbeiten Sie die Schritte in dieser Reihenfolge ab. Rechts steht, was zu sehen
sein soll.

1. **Öffentliche Website.** `/`, `/so-funktioniert-es`, `/preise`,
   `/haeufige-fragen`, `/kontakt`, `/datenschutz-und-loeschung`. Erwartet:
   jeweils Seite mit Kopf- und Fußbereich, Preis 24,90 EUR je erzeugter
   Mieterabrechnung, klarer Hinweis, dass Originaldateien nicht dauerhaft
   gespeichert werden.
2. **Rechtstexte.** `/impressum`, `/datenschutzerklaerung`, `/agb`,
   `/widerrufsbelehrung`. Erwartet: erreichbare Seiten, erkennbar als noch
   nicht anwaltlich freigegebene Platzhalter.
3. **Registrierung.** `/registrieren` mit einer beliebigen Adresse auf
   `beispiel.invalid`. Erwartet: Weiterleitung auf den Hinweis zur
   E-Mail-Bestätigung. Den Bestätigungslink finden Sie in
   `storage/logs/laravel-JJJJ-MM-TT.log`.
4. **Anmeldung.** `/anmelden` mit `demo@smart-abrechnen.test`. Erwartet:
   Weiterleitung auf `/app`.
5. **Übersicht.** `/app`. Erwartet: die drei Abrechnungsläufe mit Zustand und
   nächstem Schritt, dazu die Objekte.
6. **Objekt und Einheiten.** `/app/objekte`, dann `Buchenstrasse 40` öffnen.
   Erwartet: sechs Einheiten mit Wohnfläche, Lage und Miteigentumsanteil, Summe
   432,00 m².
7. **Mietverhältnis mit Zeitachse.** In Wohnung 3 die Mietverhältnisse öffnen.
   Erwartet: zwei Mietverhältnisse, das erste bis 30.06.2025, das zweite ab
   01.07.2025, lückenlose Zeitachse. In Wohnung 6 zusätzlich der Leerstand vom
   01.08.2025 bis 31.08.2025.
8. **Upload mit Drag and drop.** Lauf 1 (Entwurf) öffnen, `Upload`. Legen Sie
   eine beliebige PDF-Datei in das Feld. Erwartet: Fortschritt je Abschnitt,
   Hinweis auf die Löschung des Originals, Datei erscheint in der Liste.
9. **Analysestatus.** Im selben Lauf `Analyse`. Der Zeitplaner beziehungsweise
   `smartabrechnen:queue-slice` muss dafür laufen. Erwartet: Status je Unterlage,
   selbsttätige Aktualisierung, erkannte Dokumentart mit Konfidenz. Der
   Testprovider liefert immer dieselbe Beispielantwort; eine abweichende
   Dokumentart ordnen Sie von Hand zu.
10. **Kostenprüfung mit Warnbanner und Dublette.** Lauf 2 öffnen,
    `Kostenprüfung`. Erwartet: Warnbanner für Dublettenverdacht und für nicht
    umlagefähige Kosten, Gruppierung nach Kostenart, jede Position einzeln zu
    bestätigen oder zu verwerfen, Weiterschalten erst nach vollständiger
    Entscheidung.
11. **Vorauszahlungen.** Lauf 3, `Vorauszahlungen`. Erwartet: je
    Mietverhältnis Soll und Ist, taggenau auf die Mietzeit begrenzt, die
    Annahme Ist gleich Soll ist ausdrücklich zu bestätigen.
12. **Verteilerschlüssel mit Anteilsprüfung.** Lauf 3,
    `Verteilerschlüssel`. Erwartet: je Kostenart ein Schlüssel mit Nenner und
    Zählern je Einheit, Hinweis, wenn die Summe der Zähler vom Nenner
    abweicht.
13. **Prüfbericht mit Blocker und Warnung.** Lauf 3, `Prüfbericht`. Erwartet:
    Prüfaufgaben nach Schwere, Warnungen sind entscheidbar, Bestandenes ist
    aufgeführt. Einen Blocker provozieren Sie, indem Sie in Schritt 12 einen
    Nenner auf 0 setzen und speichern. Erwartet: Blocker, und die Vorschau
    lässt sich nicht mehr erzeugen. Setzen Sie den Wert danach zurück.
14. **Vorschau mit Wasserzeichen.** Lauf 3, `Vorschau`. Erwartet:
    Mieterabrechnungen und Eigentümerübersicht im Viewer, Wasserzeichen auf
    jeder Seite, unverbindliche Preisschätzung, Bestätigungskästchen nicht
    vorangekreuzt.
15. **Checkout.** Ohne Stripe-Schlüssel nicht prüfbar, siehe Abschnitt 6.
16. **Datenschutzseite mit Datenexport.** `/app/datenschutz`. Erwartet:
    Übersicht der gespeicherten Daten, Datenexport als ZIP anstoßen und
    herunterladen, Kontolöschung mit Frist anstoßen und zurücknehmen.
17. **Adminbereich mit Livegang-Blockern.** Abmelden, mit
    `demo-admin@smart-abrechnen.test` anmelden, `/admin` aufrufen. Erwartet:
    Weiterleitung auf die Einrichtung des Zweitfaktors, weil der Adminbereich
    einen zweiten Faktor verlangt. Nach der Einrichtung mit einer
    Authenticator-App: Liste der Livegang-Blocker, Healthcheck, Kennzahlen,
    Datenschutzmonitor. Mit dem Kundenkonto beantwortet `/admin` mit 404.

Nicht Teil dieses Prüfpfads ist die Heizkostenmatrix
(`/app/abrechnungen/…/heizkosten`). Sie wird derzeit überarbeitet.

---

## 5. Mandantentrennung selbst prüfen

1. Melden Sie sich mit `demo@smart-abrechnen.test` an und notieren Sie aus der
   Seeder-Ausgabe die Kennung des fremden Objekts `Ahornallee 5` (Zeile
   `Fremdes Objekt`).
2. Rufen Sie mit diesem Konto `/app/objekte/<fremde Kennung>/einheiten` auf.
   Erwartet: **404**. Kein Objektname, keine Adresse, keine Mieterdaten des
   zweiten Mandanten.
3. Wiederholen Sie das mit einer fremden Laufkennung, etwa
   `/app/abrechnungen/<fremde Kennung>`. Erwartet: ebenfalls 404.
4. Gegenprobe: Melden Sie sich mit `demo-zweitkunde@smart-abrechnen.test` an
   und rufen dieselbe URL auf. Erwartet: Seite wird angezeigt.

Jede andere Antwort als 404 oder 403 ist ein meldepflichtiger Fund, auch wenn
nur ein Name oder ein Betrag sichtbar wird.

---

## 6. Was ohne echte Zugangsdaten NICHT prüfbar ist

Bitte melden Sie diese Punkte nicht als Fehler:

- **Echte KI-Auswertung eines eigenen PDF.** Der Testprovider liefert
  hinterlegte Beispielantworten. Die ausgelesenen Werte passen deshalb nicht zu
  Ihrer Datei, und die Dokumentart ist gegebenenfalls von Hand zuzuordnen.
- **Echter Stripe-Checkout.** Ohne Stripe-Schlüssel entsteht kein
  Zahlungsvorgang. Die Zahlungsseite eines Laufs endet ohne Schlüssel mit einer
  Fehlerseite; die Zahlung, die Rückleitung, der signaturgeprüfte Webhook, die
  Finalisierung und die Rechnung sind ausschließlich mit Stripe-Testschlüsseln
  prüfbar und in den automatisierten Tests abgedeckt.
- **Echter Mailversand.** Mit `MAIL_MAILER=log` verlässt keine Mail den
  Rechner. Zustellbarkeit, Absenderreputation und der Abmeldelink im echten
  Postfach sind nicht prüfbar.
- **SFTP-Ablage.** Mit `FILESYSTEM_DISK=local` liegen Ergebnisdateien lokal.
  Rechte, Pfade und Störungsverhalten des IONOS-Speichers sind nicht prüfbar.
- **MariaDB-spezifisches Verhalten.** Der Test läuft auf SQLite. Der Nachweis
  gegen MariaDB 10.11 und 11.x erfolgt in der CI.

---

## 7. Fehler brauchbar melden

Bitte je Fund eine Meldung mit:

1. Datum und Uhrzeit, Konto, aufgerufene URL.
2. Was Sie erwartet haben und was tatsächlich geschah, mit Bildschirmfoto.
3. Die Korrelation-ID. Die Anwendung protokolliert die Verarbeitung einer
   Unterlage mit dem Feld `correlation_id`; der Wert ist die Kennung der
   Unterlage. Für Fehler in der Auswertung finden Sie ihn mit:

   ```bash
   grep correlation_id storage/logs/laravel-$(date +%Y-%m-%d).log | tail -n 20
   ```

   Für alle übrigen Seiten nennen Sie stattdessen die Kennung aus der URL, also
   die Zeichenfolge nach `/abrechnungen/`, `/objekte/` oder `/einheiten/`. Sie
   ordnet die Meldung eindeutig einem Datensatz zu.

4. Den Logauszug zur Uhrzeit. Die Logdatei liegt unter
   `storage/logs/laravel-JJJJ-MM-TT.log`, ältere Meldungen zusätzlich in
   `storage/logs/laravel.log`.
5. Ob der Fund reproduzierbar ist, und in welchen Schritten.

Keine echten Kunden-, Mieter- oder Bankdaten in Fehlermeldungen aufnehmen.

---

## 8. Demodaten wieder entfernen

Die Demodaten liegen ausschließlich in der SQLite-Datei und in
`storage/app`. Vollständiges Entfernen:

```bash
# Datenbank leeren, Migrationen neu, nur der produktive Grundstock
php artisan migrate:fresh --seed

# erzeugte Vorschau-PDF, Exportdateien und Reste des Kurzzeitbereichs entfernen
rm -rf storage/app/private/* storage/app/public/* storage/app/temporary-uploads/*
```

Oder radikal, wenn Sie die Testumgebung ganz auflösen wollen:

```bash
rm -f database/database.sqlite
rm -rf storage/app/private/* storage/app/public/* storage/app/temporary-uploads/*
```

Der Demodaten-Seeder legt beim zweiten Aufruf nichts doppelt an. Er bricht mit
dem Hinweis ab, dass die Demodaten bereits vorhanden sind, und nennt den Weg
über `migrate:fresh --seed`.
