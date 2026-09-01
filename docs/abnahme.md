# Abnahmeprotokoll: Smart Abrechnen

**Projekt:** KI-gestütztes Nebenkostenabrechnungsportal, `smart-abrechnen.de`
**Betreiber:** Hausverwaltung Müller GmbH
**Gegenstand:** unabhängige Abschlussprüfung gegen Abschnitt 24 des
Entwicklungsauftrags (Definition of Done) sowie gegen das Threat Model in
[../ARCHITECTURE.md](../ARCHITECTURE.md), Abschnitt 7
**Datum der Prüfung:** 01.09.2026
**Rolle des Prüfers:** Prüfer, nicht Erbauer. Jede Aussage ist mit Datei,
Zeile, Testname oder Befehlsausgabe belegt.

## Ergebnis in Kürze

Abschnitt 24 nennt **21 Punkte**. Davon sind **18 vollständig erfüllt**, **2
sind mit einer benannten Einschränkung erfüllt** (IONOS-SMTP am echten Postfach,
Übertragungsschritt des SFTP-Deployments) und **1 ist planmäßig offen**, nämlich
die Auflösung aller Livegang-Blocker im Adminbereich.

Keiner der drei nicht vollständig erfüllten Punkte hängt an offener
Entwicklungsarbeit. Alle drei hängen an Angaben, Verträgen, Schlüsseln oder
Assets des Betreibers.

In der Prüfung wurde **ein echter Fehler** gefunden und behoben: die Umrechnung
eines Eurobetrags aus der Kostenprüfung lief über einen `float`-Zwischenschritt
und verstieß damit gegen Grundsatz 8. Dazu ist ein Test entstanden.

Prüfstand nach der Korrektur:

```
php artisan test
  Tests:    1715 passed (11586 assertions)

vendor/bin/phpstan analyse --no-progress --memory-limit=1G
  [OK] No errors

vendor/bin/pint --test
  {"tool":"pint","result":"passed"}
```

---

## 1. Definition of Done, Punkt für Punkt

Legende: **erfüllt** bedeutet, dass die Erfüllung durch einen im Repository
vorhandenen, ausgeführten Nachweis belegt ist. **erfüllt mit Einschränkung**
bedeutet, dass der Code vollständig und getestet ist, der Nachweis am echten
Zielsystem aber aussteht und benannt wird.

| Nr. | Punkt aus Abschnitt 24 | Bewertung | Nachweis |
| --- | --- | --- | --- |
| 1 | Ein Nutzer kann ungeordnete Unterlagen hochladen | erfüllt | Chunk-Upload mit Zusammensetzung, Archiventpackung und Klassifikation: `Feature/Upload/ChunkUploadTest`, `Feature/Upload/UploadPipelineTest`, `Feature/Ai/DocumentClassificationTest`. Die Sortierung leistet die Klassifikation, eine Vorsortierung durch den Nutzer ist nicht verlangt |
| 2 | Hausgeldabrechnung und Grundsteuer werden zuverlässig getrennt und zusammengeführt | erfüllt | `Feature/Reconciliation/HausgeldReconciliationTest`, `Feature/Reconciliation/PropertyTaxReconciliationTest`, `Unit/Domain/Weg/HausgeldCostExtractorTest`, `Unit/Domain/Weg/PropertyTaxMergerTest`, dazu die handprüfbare Fixture `Unit/Domain/Reference/CondominiumReferenceTest` |
| 3 | Alle extrahierten Werte sind mit Quelle prüfbar | erfüllt | Jedes Feld trägt Dokumenttyp, Seite, Fundstelle, Konfidenz, Provider, Modell und Zeitpunkt. Schemazwang in `app/Services/Ai/Schemas/FieldNode.php`, Persistenz in `app/Services/Ai/Integration/ExtractedFieldPersister.php`. Nachweis: `Feature/Ai/ExtractedFieldPersistenceTest`, `Unit/Ai/JsonSchemaValidatorTest` |
| 4 | Quelldateien werden nur kurzfristig verarbeitet und danach automatisch gelöscht | erfüllt | Löschung nach erfolgreicher Extraktion und nach endgültigem Fehler, unabhängiger TTL-Cleanup, harte Obergrenze 120 Minuten: `Feature/Deletion/SourceDeletionTest`, `Feature/Deletion/TtlCleanupTest::test_ttl_ist_hart_auf_120_minuten_begrenzt`, Wiederholung offener Löschungen in `Feature/Deletion/RetryFailedDeletionsTest` |
| 5 | Weder SFTP, Datenbank, Backup, Log noch KI-Provider enthält nach Abschluss eine Originaldatei oder vollständigen OCR-Inhalt | erfüllt | Eigenständig nachgeprüft, siehe Abschnitt 2 dieses Protokolls. `Feature/Deletion/NoOriginalLeakTest` prüft Artefakt-Disk, Queue-Payload, Log und Datenbank gegen einen Markerinhalt; `Feature/Ai/ProviderFileDeletionTest` die Providerlöschung; `Unit/Privacy/BackupManifestAuditTest` und `Feature/Privacy/BackupManifestCommandTest` den Backupausschluss |
| 6 | Dauerhaft verbleiben nur erforderliche strukturierte Extraktionsdaten und minimale Fundstellenausschnitte | erfüllt | `NoOriginalLeakTest::test_dauerhaft_verbleiben_nur_die_erlaubten_felder` prüft die Spaltenliste von `documents` gegen eine abgeschlossene Positivliste. Fundstellen sind auf 240 Zeichen begrenzt, erzwungen im Validator (`JsonSchemaValidator.php:419`) und zusätzlich beim Persistieren gekappt (`ExtractedFieldPersister.php:216`) |
| 7 | Der Nutzer wird deutlich auf die eigene Aufbewahrungspflicht hingewiesen | erfüllt | Hinweis im Uploaddialog und in den erzeugten Abrechnungen: `Feature/Upload/UploadZoneViewTest`, `Unit/Privacy/PrivacyDisclosureTest`, `Feature/Privacy/PrivacyPageTest` |
| 8 | Keine KI berechnet Geldanteile frei | erfüllt | Eigenständig nachgeprüft, siehe Abschnitt 3. Strukturell belegt: `app/Domain` hat keine einzige Abhängigkeit auf `App\Services\Ai` oder auf Eloquent, und jeder Mieteranteil entsteht als `$line->share` in der Domain-Engine (`CalculateBillingRun.php:300`). Der Schemavalidator weist einen Dezimalbetrag als Cent-Wert ausdrücklich ab (`JsonSchemaValidator.php:241` bis 259) |
| 9 | Der Wizard ist vollständig fortsetzbar | erfüllt | Der erreichte Schritt liegt in `billing_runs.wizard_step`, nicht in der Session (ADR-012). Nachweis: `Feature/Wizard/WizardFrameTest::test_der_ablauf_ist_unterbrechbar_und_ohne_datenverlust_fortsetzbar` prüft, dass eingegebene Vorauszahlungen nach Rückkehr wieder erscheinen, und `::test_der_erreichte_schritt_wird_gespeichert_und_nicht_zurueckgesetzt`, dass ein Rücksprung den Fortschritt nicht verliert |
| 10 | Vorschauen tragen auf jeder Seite ein serverseitiges großes Wasserzeichen | erfüllt | Eigenständig nachgeprüft, siehe Abschnitt 4. `Feature/Pdf/WatermarkTest` zählt die Vorkommen im entpackten Seiteninhalt und vergleicht sie mit der Seitenzahl, prüft also je Seite und nicht nur auf Vorhandensein |
| 11 | Der Nutzer bezahlt erst danach per Stripe | erfüllt | Ohne gültige Vorschau und ohne Bestätigung kein Checkout: `Feature/Wizard/PreviewStepTest::test_ohne_bestaetigung_gibt_es_keinen_checkout` und `::test_ohne_gueltige_vorschau_ist_keine_bestaetigung_moeglich`, dazu `Feature/Payment/CheckoutTest` |
| 12 | Nur ein verifizierter Webhook löst die Finalisierung aus | erfüllt | Eigenständig nachgeprüft, siehe Abschnitt 5. Belegend insbesondere `Feature/Payment/StripeWebhookTest::test_der_browser_redirect_allein_schaltet_nicht_frei` und `::test_eine_falsche_signatur_wird_abgelehnt_und_schaltet_nichts_frei` |
| 13 | Final-PDFs werden neu und ohne Wasserzeichen erzeugt | erfüllt | `Feature/Pdf/FinalIsRegeneratedTest::test_kein_renderweg_nimmt_ein_bestehendes_pdf_entgegen` und `::test_geloeschte_vorschau_verhindert_die_finalversion_nicht`, dazu `WatermarkTest::test_finalversion_traegt_kein_wasserzeichen`. Grundlage ist ADR-013 |
| 14 | Die HVM-Rechnung wird korrekt erstellt | erfüllt | `Feature/Payment/OperatorInvoiceTest` (Netto, Steuer, Brutto getrennt, Anschrift aus dem Konto, Storno mit Referenz), `Feature/Pdf/OperatorInvoicePdfTest`, `Feature/Payment/InvoiceNumberSequenceTest` für den lückenlosen Nummernkreis. Fehlende Betreiberstammdaten blockieren die produktive Rechnung statt sie zu erfinden: `::test_fehlende_pflichtangaben_blockieren_die_erzeugung` |
| 15 | Das Konto übernimmt die Daten für das Folgejahr | erfüllt | `Feature/FollowUpYear/FolgejahresuebernahmeTest`, `Feature/FollowUpYear/FolgejahresCtaTest` |
| 16 | Erinnerungen in Q1, Q2, Q3 und am 1. Dezember funktionieren und sind deaktivierbar | erfüllt | Termine 15.01., 15.04., 15.07. und 01.12., belegt in `Unit/Reminder/ReminderScheduleTest::test_standardtermine_liegen_auf_den_vorgegebenen_tagen`. Versand, Deduplizierung, Unterdrückung bei finalisiertem Lauf und Abschaltung global, je Objekt und je Fenster: `Feature/Reminder/ErinnerungsversandTest` mit 23 Testmethoden, Abmeldung über signierten Link ohne Anmeldung: `Feature/Reminder/AbmeldelinkTest` |
| 17 | IONOS-SMTP über `kontakt@smart-abrechnen.de` funktioniert | erfüllt mit Einschränkung | Konfiguration, Absender, Vorlagen, Zustellprotokoll, Sperrlogik und Redigierung sind vollständig und getestet: `Feature/Mail/MailVersandProtokollTest`, `Feature/Mail/TransaktionsmailInhaltTest`. Der Versand läuft im Test über einen Fake. **Offen:** der Nachweis am echten Postfach, weil dessen Passwort ein Livegang-Blocker ist. Verantwortlich: Betreiber |
| 18 | Das SFTP-Deployment ist reproduzierbar und rollbackfähig | erfüllt mit Einschränkung | `.github/workflows/deploy.yml` baut ein reproduzierbares Releasepaket und führt die Testsuite vor der Paketierung aus. Das Rollbackverfahren über getrennte Releaseverzeichnisse und einen Releasezeiger ist dokumentiert. **Offen:** der Übertragungsschritt ist bewusst nicht aktiviert, der Job gibt derzeit nur die Schrittfolge aus (`deploy.yml:111` bis 120). Er wartet auf Zielpfad und Zugangsdaten. Verantwortlich: Betreiber stellt den Zugang, technische Betreuung aktiviert |
| 19 | MariaDB 10.11 und 11.x sind unterstützt | erfüllt | Die CI fährt die Migrationen, einen Rollback und die Testsuite gegen `mariadb:10.11` und `mariadb:11.4` (`.github/workflows/ci.yml:59` bis 160). Zur Laufzeit prüft `SystemHealthCheck::isSupportedMariaDbVersion()` die Serverversion und meldet eine nicht freigegebene Version im Adminbereich |
| 20 | Tests, Sicherheitschecks, Backup und Restore sind dokumentiert | erfüllt | Teststrategie in ARCHITECTURE.md Abschnitt 9, Threat Model in Abschnitt 7 mit Testnachweisen, Prüfbefehle in der README, Betriebsdokumente in [betrieb/backup-und-restore.md](betrieb/backup-und-restore.md) und [betrieb/loeschkonzept-betrieb.md](betrieb/loeschkonzept-betrieb.md) |
| 21 | Alle Livegang-Blocker im Adminbereich sind gelöst | nicht erfüllt, planmäßig offen | `LaunchBlockerCheck` meldet aktuell sechs blockierende Punkte und eine offene kaufmännische Entscheidung. Alle sieben verlangen ausschließlich Angaben, Verträge, Schlüssel oder Assets des Betreibers. Die vollständige Liste steht in Abschnitt 7 dieses Protokolls |

---

## 2. Eigene Nachprüfung: verbleiben Originaldateien oder vollständige OCR-Inhalte?

Die Prüfung hat aktiv nach Gegenbeispielen gesucht, statt dem Bericht der
Entwicklung zu folgen.

**Schreibzugriffe auf die Artefakt-Disk.** Gesucht wurde nach jedem `put()` auf
einer Disk. Ergebnis: es gibt genau zwei Wege in die dauerhafte Ablage,
`Services/Pdf/Store/GeneratedDocumentWriter.php:33` und
`Application/Privacy/CreateDataExport.php:43`. Beide rufen
`ArtifactStorage::put()` mit einem `ArtifactType`. Das Enum `ArtifactType` kennt
sieben Werte, alle für vom System erzeugte Ergebnisse, und bewusst keinen für
einen Originalupload, ein Seitenbild oder einen OCR-Text. Zusätzlich prüft
`ArtifactStorage::assertArtifactContents()` die Magic Bytes, ein Inhalt ohne
`%PDF-` oder ZIP-Signatur wird abgewiesen. Der generische Kopierweg
`copyFromDisk()` wirft ausnahmslos (`ArtifactStorage.php:96`). Die drei
weiteren `put()`-Aufrufe in `ChunkAssembler.php:110`,
`AssembleUpload.php:213` und `ExpandArchive.php:185` schreiben nachprüfbar auf
`TemporaryUploadStorage`, nicht auf die Artefaktablage. Damit ist die
Ablage einer Quelldatei nicht organisatorisch untersagt, sondern typseitig
ausgeschlossen.

**Logaufrufe mit Dateiinhalt.** Im gesamten `app/` gibt es fünf `Log::`-Aufrufe.
Alle liegen in der Zahlungs- und Mailschicht und übergeben ausschließlich
Referenz-IDs und `getMessage()` einer Ausnahme
(`StripeWebhookController.php:64`, `SendFinalizationMails.php:72`, `:97`,
`:201`, `HandleStripeEvent.php:204`). Die Dokumentpipeline schreibt keinen
Logeintrag mit Inhalt. `NoOriginalLeakTest::test_kein_dateiinhalt_in_den_logs`
belegt das gegen einen Markerinhalt. Restrisiko: eine Ausnahmemeldung ist
Fremdtext; das ist als niedriges Risiko in ARCHITECTURE.md Abschnitt 11
aufgenommen.

**Queue-Payloads mit mehr als IDs.** Es gibt keinen Laravel-Queue-Job im
Projekt, `ShouldQueue` kommt nicht vor. Alle Teilschritte laufen über
`DatabaseJobQueue`. Deren einziger Einstellweg `push()` schickt jeden Payload
durch `JobPayloadGuard::sanitize()`, das verschachtelte Strukturen, Werte über
128 Zeichen, Zeilenumbrüche und inhaltsverdächtige Schlüssel wie `text`, `ocr`,
`datei`, `pfad` oder `base64` mit einer Ausnahme abweist statt still zu kürzen.
`pushOnce()` delegiert an `push()`. Ein Umweg an der Sperre vorbei besteht
nicht, weil `ProcessingJob` außerhalb von `DatabaseJobQueue` nirgends erzeugt
wird.

**Rohe KI-Anfragen und Antworten.** Die Tabelle `ai_calls` hat keine Spalte für
Prompt oder Antworttext; gespeichert werden Provider, Modell, Zweck,
Tokenzahlen, Kosten, Dauer, Status und Fehlercode
(Migration `2026_01_01_000800`, Zeilen 54 bis 82). Prompts werden nur als
Version und SHA-256-Hash in `ai_prompt_versions` geführt.

**Bewertung:** Punkt 5 der Definition of Done ist erfüllt. Es wurde kein
Gegenbeispiel gefunden.

Eine Einschränkung des Nachweises, nicht der Sache:
`NoOriginalLeakTest::test_kein_dateiinhalt_in_der_gesamten_datenbank` prüft
fünf namentlich aufgeführte Tabellen, nicht das gesamte Schema. Eine künftig
ergänzte Tabelle fällt nicht automatisch in die Prüfung. Vorschlag ohne Eingriff
in dieser Prüfung: die Tabellenliste aus dem Schema ableiten, sobald der
Testlauf verbindlich gegen MariaDB fährt.

---

## 3. Eigene Nachprüfung: berechnet eine KI Geldanteile?

Gesucht wurde nach einer Stelle, an der ein KI-Ergebnis in einen Betrag oder
Anteil fließt, ohne durch die Domain-Engine zu laufen.

Die KI liefert ausschließlich Werte, die auf einem Dokument stehen, etwa
`gesamtkosten_cent` oder `anteil_einheit_cent` aus der Hausgeldabrechnung. Das
ist Extraktion und nach Grundsatz 1 zulässig. Entscheidend ist die Grenze
danach, und sie hält:

- `app/Domain` enthält keine Referenz auf `App\Services\Ai` und keine auf
  `App\Models`. Die Engine kann ein KI-Ergebnis technisch nicht sehen.
- Jeder Mieteranteil wird als `$line->share->cents` aus dem Ergebnisobjekt der
  Engine geschrieben (`CalculateBillingRun.php:300`). Es gibt keinen zweiten
  Schreibweg auf `share_cent`.
- Der Schemavalidator lehnt einen Dezimalwert oder eine Zeichenkette als
  Cent-Betrag ausdrücklich ab, statt ihn stillschweigend umzurechnen
  (`JsonSchemaValidator.php:241` bis 259, Verstoßcode
  `BETRAG_NICHT_INTEGER`). Ein KI-Betrag ist damit immer ein Integer in Cent.
- Der Systemprompt grenzt zusätzlich ausdrücklich ab: „Du berechnest keine
  Mieteranteile, keine Summen, keine Verteilungen und keine Zeitanteile“
  (`AbstractSystemPrompt::boundariesBlock()`). Das ist die zweite Linie, nicht
  die Absicherung selbst.

**Bewertung:** erfüllt.

### Nebenbefund zu Grundsatz 8, behoben

Bei der Suche nach Float-Arithmetik auf Geldwerten wurde eine echte Abweichung
gefunden. `app/Http/Requests/Review/UpdateCostItemRequest.php` rechnete den in
der Kostenprüfung eingegebenen Eurobetrag mit
`(int) round(((float) $normalisiert) * 100)` in Cent um. Die
Schwesterimplementierung `StorePrepaymentsRequest::cent()` machte dasselbe
korrekt über `BigDecimal`. Der Wert fließt in `cost_items.amount_cent` und damit
in die Abrechnung.

Praktisch fing `round()` den Fehler in den geprüften Größenordnungen auf, die
Konstruktion widerspricht aber Grundsatz 8 und ist in einer Geldrechnung nicht
tragbar. Behoben durch Umstellung auf `BigDecimal` mit
`RoundingMode::HALF_UP`, identisch zur Wizard-Implementierung. Neu entstandener
Nachweis:
`Feature/Review/CostReviewTest::test_eurobetraege_werden_exakt_in_cent_umgerechnet`
mit sechs Betragsvarianten, darunter Tausendertrennzeichen, kleiner Restcent und
negative Gutschrift, sowie
`::test_ein_unleserlicher_betrag_wird_nicht_geschaetzt` für Grundsatz 5.

Weitere `round()`-Vorkommen wurden geprüft und sind unkritisch: sie betreffen
Laufzeiten in Millisekunden, Tokenschätzungen, Konfidenzanzeigen in Prozent und
eine Kennzahl im Adminbereich, keine Abrechnungsbeträge.

---

## 4. Eigene Nachprüfung: Wasserzeichen auf jeder Seite

Der Nachweis ist stärker als eine Sichtprüfung. `Feature/Pdf/WatermarkTest`
entpackt die Seiteninhalte des erzeugten PDF und zählt die Vorkommen des
Wasserzeichentexts. Der Test verlangt Gleichheit von Vorkommen und Seitenzahl
bei mindestens drei Seiten, und zwar für die Mieterabrechnung, die Anlage nach
§ 35a EStG und die Eigentümerübersicht.

Zusätzlich belegt
`::test_wasserzeichen_ist_teil_des_seiteninhalts_und_keine_entfernbare_ebene`,
dass der Text nicht im Klartext im Dateikörper steht und dass das PDF keine
`/OCProperties`, `/OCGs` oder `/Watermark`-Strukturen enthält. Es ist also keine
im Betrachter abschaltbare Ebene, sondern eingebrannter Seiteninhalt. Technisch
geschieht das über `SetWatermarkText` von mPDF, angewandt bei jedem Seitenbeginn
(`app/Services/Pdf/Watermark/WatermarkStamp.php`).

Die Finalversion entsteht auf demselben Renderweg mit abgeschalteter
Wasserzeicheneinstellung und wird vollständig neu aus dem gesperrten
Berechnungsstand erzeugt, siehe ADR-013.

**Bewertung:** Punkte 10 und 13 sind erfüllt.

---

## 5. Eigene Nachprüfung: schaltet nur ein verifizierter Webhook frei?

`StripeWebhookController` prüft die **rohe** Nutzlast gegen die Signatur, nicht
`$request->all()`. Eine Verifikationsausnahme führt zu HTTP 400 und einem
Eintrag, der nur Signaturstatus, Ereignisart und einen Digest der Nutzlast
speichert, nicht die Nutzlast selbst.

Die belegenden Fälle in `Feature/Payment/StripeWebhookTest`: falsche, fehlende
und veraltete Signatur, abweichender Betrag, abweichende Währung, abweichender
Abrechnungslauf, noch offene Zahlung, doppelte Zustellung, abgelaufene und
fehlgeschlagene Zahlung, Erstattung, Teilerstattung, Rückbelastung. Am
deutlichsten ist `::test_der_browser_redirect_allein_schaltet_nicht_frei`: die
Rückleitung aus dem Browser bewirkt keine Finalisierung.

**Bewertung:** erfüllt.

---

## 6. Sicherheitsprüfung

### 6.1 Threat Model, T1 bis T12

Alle zwölf Bedrohungen aus ARCHITECTURE.md Abschnitt 7 wurden gegen den Code
geprüft. Die Gegenmaßnahme ist in jedem Fall wirksam vorhanden und durch
Tests gedeckt. Die Zuordnung Bedrohung zu Testklasse ist in ARCHITECTURE.md
Abschnitt 7 eingetragen und wurde dabei erstmals mit den tatsächlich
vorhandenen Testnamen belegt statt allgemein beschrieben.

Zwei Punkte verdienen eine ausdrückliche Bewertung:

- **T3, Prompt Injection.** Der Sicherheitsbaustein ist nicht nur „in jedem
  Prompt vorhanden“, er ist strukturell unumgehbar. `build()` in
  `AbstractSystemPrompt` ist `final` und setzt den Baustein immer an zweiter
  Stelle. Alle fünf Prompts erben von dieser Klasse, und `PromptDefinition`
  wird im gesamten Projekt an genau einer Stelle erzeugt, nämlich in
  `build()` selbst (`AbstractSystemPrompt.php:93`). Eine leere Konfiguration
  fällt auf `SECURITY_PROMPT_FALLBACK` zurück, es kann also kein Prompt ohne
  Sicherheitshinweis hinausgehen. Nachweis:
  `Unit/Ai/PromptRegistryTest::test_leerer_sicherheitsbaustein_wird_durch_mindesttext_ersetzt`.
- **T6, Daten in Logs und Queue.** Siehe Abschnitt 2. Wirksam.

### 6.2 Routen ohne Autorisierung

Geprüft wurde die vollständige Ausgabe von `php artisan route:list`: 137
Routen, davon 63 schreibend.

Ergebnis für die schreibenden Routen:

| Gruppe | Anzahl | Middleware |
| --- | --- | --- |
| `app/*` | 43 | `web, auth, organisation` |
| `app/*` mit E-Mail-Zwang | 2 | `web, auth, organisation, can:email-verified` |
| `app/uploads/*` | 3 | `web, auth, organisation, throttle:uploads` |
| `admin/*` | 8 | `web, auth, verified, can:access-admin, RequireAdminTwoFactor` |
| `abmelden` | 1 | `web, auth` |

Jede schreibende Route der Anwendung trägt damit Anmeldung und
Mandantenkontext. Die Middleware `EnsureOrganizationContext` verwirft einen
manipulierten Sessionwert statt ihn zu übernehmen und prüft die Mitgliedschaft
zusätzlich objektbezogen über `belongsToOrganization()`, also nicht nur gegen
eine Liste von Kennungen.

Die Policy-Prüfung erfolgt in den Controllern. Drei Controller kommen ohne
`authorize()` aus und wurden einzeln geprüft; alle drei führen stattdessen eine
ausdrückliche Zugehörigkeitsprüfung durch und antworten mit 404 statt 403, was
eine Existenzauskunft vermeidet:
`DownloadController::assertAccessible()` (Zeilen 58 bis 81),
`FollowUpYearController::start()` (Zeilen 41 bis 57) und
`WizardController`, der als abstrakte Basis dient, während alle vier
Unterklassen `authorize()` aufrufen.

**Zulässige Ausnahmen ohne Anmeldung**, jeweils begründet:

| Route | Schutz | Bewertung |
| --- | --- | --- |
| `POST webhooks/stripe` | Signaturprüfung der rohen Nutzlast, `throttle:webhooks`, CSRF ausgenommen | zulässig, so vorgesehen. Gedeckt durch `::test_die_webhook_route_verlangt_kein_csrf_token_und_keine_anmeldung` und die Signaturfälle |
| `POST anmelden`, `registrieren`, `passwort-vergessen`, `passwort-neu` | `guest`, Rate Limits 5 bis 10 pro Minute | zulässig, Anmeldewege können keine Anmeldung verlangen |
| `GET e-mail-bestaetigen/{user}/{hash}` | `signed`, `throttle:6,1` | zulässig |
| `GET erinnerungen/abmelden/{token}`, `erinnerungen/aktivieren/{token}` | `signed` | zulässig. Ein Zustandswechsel über GET ist hier gewollt, weil ein Abmeldelink aus einer E-Mail ohne Formular funktionieren muss. Der Link enthält keine Kundendaten, belegt in `AbmeldelinkTest::test_abmeldelink_ist_signiert_und_ohne_kundendaten` |
| öffentliche Seiten und Rechtstexte | keine | zulässig, kein Zustandswechsel |
| `GET up` | keine | Laravel-Healthcheck. Er gibt keine Konfiguration und keine Daten aus. Empfehlung ohne Eingriff: am Zielsystem auf die Monitoring-Adresse einschränken |

**Befund:** keine schreibende Route ohne Autorisierung. Keine Korrektur nötig.

### 6.3 Massenzuweisung

Alle 47 Modelle in `app/Models` deklarieren `$fillable` oder `$guarded`. Es gibt
kein `$guarded = []` und keinen `Model::unguard()`-Aufruf im Projekt.

Zusätzlich wurde geprüft, ob irgendwo ein Requestarray direkt in `create()` oder
`fill()` fließt. Ergebnis: nein. Die Formularklassen unter
`app/Http/Requests` geben ausdrücklich zusammengesetzte Arrays zurück, ein
`$request->all()` in einen Schreibvorgang kommt nicht vor. Die Modelle mit
`$guarded = ['id']` sind damit nicht exponiert.

**Befund:** keine Massenzuweisungslücke.

### 6.4 Geheimnisse und personenbezogene Echtdaten

Gesucht wurde im gesamten Repository, einschließlich Fixtures, Tests und
Kommentaren, nach Stripe-, OpenAI-, Anthropic-, AWS- und GitHub-Schlüsselmustern,
nach IBAN, nach Umsatzsteuer-Identifikationsnummern und Steuernummern sowie nach
E-Mail-Adressen.

| Suchmuster | Funde | Bewertung |
| --- | --- | --- |
| Live- und Testschlüssel der Anbieter | 2 | `sk_test_platzhalter` in `Feature/Admin/LivegangBlockerTest.php:59` und `:68`. Wörtlicher Platzhalter, kein Schlüssel |
| IBAN | 13 | ausschließlich `DE99999999999999999999` (`TestData::PLACEHOLDER_IBAN`) und die öffentlich dokumentierte Beispiel-IBAN `DE02120300000000202051`. Keine echte Bankverbindung |
| Steuer- und Umsatzsteuernummern | 10 | ausschließlich `DE123456789`, `DE000000000`, `DE000000001`. Platzhalter |
| E-Mail-Adressen | Domains geprüft | eigene Fixtures nutzen `@beispiel.invalid`, `@testhost.invalid`, `@example.test` und `@smart-abrechnen.de`. Alle übrigen Domains stammen aus Autorenangaben in `composer.lock`, also aus Bibliotheksmetadaten |
| `.env` | 1 Datei | in `.gitignore` Zeile 3 ausgeschlossen. Alle Geheimnisfelder sind leer, gesetzt ist nur ein lokaler `APP_KEY` |

**Befund:** keine Funde außer Platzhaltern, wie erwartet.

### 6.5 Prompt Injection

Siehe 6.1, T3. Der Sicherheitsbaustein ist in jedem Systemprompt und
strukturell unumgehbar.

---

## 7. Verbleibende Livegang-Blocker

Ausgabe von `LaunchBlockerCheck` gegen die aktuelle Konfiguration, nicht
abgeschrieben, sondern ausgeführt.

| Nr. | Schwere | Bereich | Was fehlt | Verantwortlich |
| --- | --- | --- | --- | --- |
| B1 | blockierend | Betreiber und Rechnung | bestätigte Steuernummer, Umsatzsteuer-Identifikationsnummer, IBAN und BIC; danach `HVM_MASTERDATA_CONFIRMED=true` | Geschäftsführung der Hausverwaltung Müller GmbH, in Abstimmung mit dem Steuerberater |
| B2 | blockierend | Zahlung | `STRIPE_KEY`, `STRIPE_SECRET` und `STRIPE_WEBHOOK_SECRET` | Betreiber über das Stripe-Konto |
| B3 | blockierend | KI-Provider | Datenschutzfreigabe für OpenAI und Anthropic. `AI_DATA_RETENTION_APPROVED` bleibt `false`, solange kein Nachweis über Zero Data Retention je Providerorganisation, Modell und genutzter Funktion vorliegt | Betreiber, mit Auftragsverarbeitungsvertrag und Retention-Dokumentation |
| B4 | blockierend | Uploads | `MALWARE_SCANNER_DRIVER=disabled`. Zu entscheiden ist `clamav`, `external` oder eine schriftliche Risikobewertung | Betreiber |
| B5 | blockierend | Recht | Impressum, Datenschutzerklärung, AGB und Widerrufsbelehrung sind Platzhalterfassungen | Betreiber über die beauftragte Rechtsanwaltskanzlei |
| B6 | blockierend | Gestaltung | in `public/ci` fehlt `Logo_HVM.svg` oder `Logo_HVM.jpg` | Betreiber; es wird kein Logo erzeugt oder nachgezeichnet |
| B7 | Entscheidung | Preis | `PRICE_CORRECTION_FREE_DAYS` ist nicht gesetzt, es gilt der Standard von null Tagen kostenfreier Korrektur | Geschäftsführung, kaufmännische Entscheidung |

Nicht technisch erkennbar und deshalb nicht in der Blockerliste, aber vor dem
Livegang zu erledigen:

| Nr. | Punkt | Verantwortlich |
| --- | --- | --- |
| B8 | Aktivierung des Übertragungsschritts in `.github/workflows/deploy.yml`, danach Smoke-Test gegen ein neues Releaseverzeichnis vor dem Umschalten des Releasezeigers | Betreiber stellt Zielpfad und Zugang, technische Betreuung aktiviert |
| B9 | Nachweis des SMTP-Versands über das echte Postfach `kontakt@smart-abrechnen.de`, dazu SPF, DKIM und DMARC | Betreiber |
| B10 | erster protokollierter Restore-Test nach [betrieb/backup-und-restore.md](betrieb/backup-und-restore.md), einschließlich des Nachweises, dass keine Original-Quelldateien wiederherstellbar sind | Betreiber benennt den Verantwortlichen für den Restore |
| B11 | Bestätigung von MariaDB-Host und Serverversion am Zielsystem, sichtbar im Admin-Healthcheck | Betreiber |
| B12 | Abnahme realer, vollständig anonymisierter Musterabrechnungen | Geschäftsführung |

---

## 8. Offene technische Punkte ohne Livegangsperre

| Nr. | Punkt | Bewertung | Vorschlag | Verantwortlich |
| --- | --- | --- | --- | --- |
| O1 | `SecurityHeaders` setzt `script-src` mit `'unsafe-eval'` (Zeile 103), weil der Alpine-Standardbuild es benötigt | mittel | Wechsel auf `@alpinejs/csp`, `x-data`-Ausdrücke umschreiben, danach `unsafe-eval` entfernen. Größerer Eingriff in das Frontend, in dieser Prüfung bewusst nicht umgebaut | technische Betreuung |
| O2 | Die Registrierung lässt über die Eindeutigkeitsprüfung erkennen, ob eine Adresse ein Konto hat. Die Meldung ist neutral formuliert, die Unterscheidbarkeit bleibt | mittel | Bei vorhandener Adresse dieselbe Bestätigungsseite ausliefern und eine Hinweismail an die bestehende Adresse senden. Berührt den Mailversand, deshalb hier nicht umgebaut | technische Betreuung |
| O3 | `NoOriginalLeakTest::test_kein_dateiinhalt_in_der_gesamten_datenbank` prüft eine feste Liste von fünf Tabellen | niedrig | Tabellenliste aus dem Schema ableiten, sobald der Testlauf verbindlich gegen MariaDB fährt | technische Betreuung |
| O4 | Logeinträge der Zahlungs- und Mailschicht enthalten `getMessage()` einer Ausnahme, also Fremdtext | niedrig | akzeptiert. Die Dokumentpipeline ist davon nicht betroffen. Bei Anbindung eines Error Monitorings erneut bewerten | technische Betreuung |
| O5 | `GET up` ist ohne Middleware erreichbar | niedrig | am Zielsystem auf die Monitoring-Adresse einschränken | technische Betreuung |

---

## 9. In dieser Prüfung vorgenommene Änderungen

Die Prüfung war als Prüfung angelegt, nicht als Weiterbau. Geändert wurde
ausschließlich der eine kleine, eindeutige Fehler aus Abschnitt 3:

| Datei | Änderung |
| --- | --- |
| `app/Http/Requests/Review/UpdateCostItemRequest.php` | `cent()` rechnet über `BigDecimal` mit `RoundingMode::HALF_UP` statt über `float`. Grundsatz 8 |
| `tests/Feature/Review/CostReviewTest.php` | neuer Datenprovider-Test mit sechs Betragsvarianten und ein Test, dass ein unleserlicher Betrag nicht geschätzt wird. Sieben zusätzliche Testfälle |

Alle größeren Funde sind in Abschnitt 8 mit Lösungsvorschlag beschrieben und
wurden ausdrücklich nicht umgebaut.

---

## 10. Nicht prüfbar in dieser Umgebung

Damit das Protokoll nicht mehr behauptet, als es belegen kann:

- **Echter SMTP-Versand.** Ohne das Postfachpasswort nicht prüfbar, siehe B9.
- **Echte SFTP-Auslieferung und Rollback am Zielsystem.** Ohne Zugang nicht
  prüfbar, siehe B8. Geprüft ist der Paketbau, nicht die Übertragung.
- **MariaDB 10.11 und 11.4.** Der lokale Testlauf fährt gegen SQLite. Der
  Nachweis liegt in der CI-Konfiguration; ein CI-Lauf wurde in dieser Prüfung
  nicht ausgelöst.
- **Echte Aufrufe bei OpenAI und Anthropic.** Ausdrücklich nicht vorgesehen,
  Tests laufen gegen gespeicherte anonymisierte Antworten. Damit ist die
  Extraktionsqualität an echten Dokumenten nicht Gegenstand dieser Prüfung,
  sondern der Abnahme anonymisierter Musterabrechnungen, siehe B12.
- **Browserbasierter E2E-Lauf.** Die End-to-End-Tests laufen als
  HTTP-Featuretests, nicht in einem echten Browser. Die fachlichen Wege sind
  damit belegt, das Verhalten der Oberfläche im Browser ist es nicht.
- **Rechtliche Bewertung der Abrechnungsergebnisse.** Nicht Gegenstand einer
  technischen Prüfung. Die Verantwortung bleibt nach Grundsatz 6 beim
  Vermieter, die Freigabe der Rechtstexte beim Betreiber, siehe B5.
