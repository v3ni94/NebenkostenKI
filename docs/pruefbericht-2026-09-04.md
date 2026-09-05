# Prüfbericht Smart Abrechnen, Stand 04.09.2026

Geprüfter Stand: Branch `claude/nebenkosten-portal-mueller-az7qff`, Commit d4d9fc6.

## 1. Ergebnis

Die Software ist aus Codesicht noch nicht für den Kundenbetrieb freigabefähig. Der Funktionslauf ist vollständig grün (Pint, PHPStan Level 6, 2.011 Tests mit 12.679 Assertions, frische Installation, öffentliche Seiten). Die inhaltliche Prüfung hat jedoch 85 bestätigte Befunde ergeben, davon 9 Blocker und 27 hoch. Die Blocker betreffen die Verteilung von Geldbeträgen, den Zahlungsabschluss und den Klickpfad des Kunden. Weitere 23 Meldungen konnten wegen einer Nutzungsgrenze nicht gegengeprüft werden und sind offen. 13 Meldungen wurden widerlegt.

| Schwere | bestätigt | ungeprüft |
|---|---|---|
| Blocker | 9 | 1 |
| Hoch | 27 | 8 |
| Mittel | 28 | 12 |
| Niedrig | 21 | 2 |

Ablauf: 15 Suchagenten (Fable 5.1, high), je Feststellung drei Widerleger (zwei Fable 5.1, einer Opus 5, alle max). Bestätigt heißt: mindestens zwei von drei Widerlegern konnten den Befund nicht entkräften. Runde 1 lief vollständig, Runde 2 nur teilweise (Geld, Zahlung, Kurzzeitbereich, Mandant), Runden 3 und 4 sind an der Nutzungsgrenze gescheitert. Die Suche ist damit nicht trocken; besonders die Bereiche Auth, Datenschutz, KI, PDF, Status, Datenmodell, Betrieb und Frontend haben nur eine Runde erhalten.

## 2. Blocker (9)

**B1 Direktzuordnung einer Kostenposition zu einer Einheit wird ignoriert.** `app/Application/Calculation/BillingRunInputAssembler.php:742`. Die Prüfmaske speichert `direct_unit_id`, der Assembler liest das Feld nicht. Grundsteuerbescheid 500,00 EUR direkt Einheit A zugeordnet: Ergebnis A 250,00 EUR, B 250,00 EUR. Empfehlung: positionsbezogenen Schlüssel für die Einheit erzeugen, Bezugsebene UNIT, Feature-Test. Aufwand mittel.

**B2 Manuelle Heizkosten: Anteile leerer oder teilbelegter Einheiten landen bei den anderen Mietern.** `app/Application/Heating/StoreManualHeatingEntries.php:448`. Positionsbetrag ist die Summe aller Einheiten, Schlüsselwerte gibt es nur für Mietverhältnisse, der Nenner ist die Summe der Zähler. Einheit B leer mit 500,00 EUR: Mieter A zahlt 1.500,00 EUR statt 1.000,00 EUR. Empfehlung: Leerstands- und Eigentümeranteil als eigenen Beteiligten führen, Positionsbetrag als Nenner. Aufwand mittel.

**B3 Soll-Vorauszahlung bei unterjährigem Zeitraum um Faktor Jahr/Zeitraum zu hoch.** `app/Application/Wizard/PrepaymentWorkspace.php:287`. Formel Monatsbetrag × 12 × Nutzungstage ÷ Zeitraumtage. Halbjahr: 1.200,00 EUR statt 600,00 EUR, mit Annahme Ist gleich Soll fließt der Betrag in den Saldo. Empfehlung: Monatsraten im Nutzungszeitraum zählen. Aufwand klein.

**B4 Direktzuordnung: Positionsbetrag ungleich Summe der Einzelbeträge wird proportional skaliert.** `BillingRunInputAssembler.php:449`. Position 1.000,00 EUR, Direktzuordnung A 300,00 EUR, B 500,00 EUR: Ergebnis A 375,00 EUR, B 625,00 EUR, kein Restanteil, keine Prüfaufgabe. Empfehlung: Positionsbetrag als Nenner, Rest als Eigentümeranteil oder Blocker. Aufwand klein.

**B5 Zahlung wird BEZAHLT gesetzt, obwohl der Lauf nicht mehr in CHECKOUT_PENDING ist.** `app/Application/Payment/HandleStripeEvent.php:183`. Nach Abbruch durch den Nutzer trifft eine asynchrone Zahlungsbestätigung (SEPA) auf PREVIEW_READY, Statuswechsel wirft, Geld eingezogen, keine Leistung, Wiederzustellung wird ignoriert. Empfehlung: Vorbedingungen prüfen, Zahlung und Statuswechsel in einer Transaktion, Abweichung protokollieren und Erstattung anstoßen. Aufwand mittel.

**B6 CSP `form-action 'self'` blockiert die Weiterleitung zu Stripe Checkout.** `app/Http/Middleware/SecurityHeaders.php:117`. Der Bezahl-POST antwortet mit Redirect auf checkout.stripe.com, Chromium-Browser blockieren das. Zahlung ist für die Mehrheit der Nutzer nicht möglich. Empfehlung: `form-action 'self' https://checkout.stripe.com`, Test anpassen. Aufwand klein. Dieser Punkt ist im Browsertest sofort sichtbar.

**B7 Preisermittlung zählt verwaiste Mieterabrechnungen früherer Berechnungsstände mit.** `app/Application/Payment/CalculatePrice.php:71`. Nach Löschen eines Mietverhältnisses und Neuberechnung bleibt dessen alte Abrechnung BERECHNET. Kunde zahlt 3 Einzelpreise, erhält 2 PDFs, Rechnung weist 3 aus. Empfehlung: Preis und Finalisierung an `active_calculation_snapshot_id` binden, Altbestände auf ERSETZT setzen. Aufwand klein.

**B8 Kein Klickpfad in den geführten Ablauf.** `resources/views/portal/abrechnungen/detail.blade.php:94`. Die Detailseite nach dem Anlegen eines Laufs enthält keinen Link zu Upload, Prüfung, Zahlung oder Download, nur einen Platzhaltertext. Der Kunde kommt ohne URL-Eingabe nicht weiter. Empfehlung: Schaltfläche für den nächsten Schritt aus `WizardProgress`, Fortschrittsleiste auf allen Schritten, Platzhalter entfernen. Aufwand klein.

**B9 Finalisierung nach bestätigter Zahlung fehlgeschlagen: kein Wiederanlauf.** `HandleStripeEvent.php:199`, `FinalizeBillingRun.php:92`. Scheitert SFTP oder mPDF nach der Zahlung, steht der Lauf auf FAILED, der Webhook wird trotzdem quittiert. Es gibt keine Admin-Route, keinen Befehl, keinen Job für den erneuten Versuch. Der Kunde hat bezahlt und erhält nichts. Drei Suchagenten haben denselben Punkt unabhängig gemeldet. Empfehlung: Admin-Aktion und Scheduler-Eintrag für FAILED mit `paid_at`, ehrlicher Hinweis auf der Warteseite. Aufwand mittel.

## 3. Hoch (27, thematisch zusammengefasst)

**Zahlung und Webhook**
- H1 Nach HTTP 500 wiederzugestellte Webhooks werden als Duplikat ignoriert (`HandleStripeEvent.php:309`). Der Datensatz wird vor der Verarbeitung angelegt, bei Wiederzustellung greift die Unique-Verletzung. Ein einmal gescheitertes Ereignis wird nie verarbeitet. Zwei Agenten unabhängig. Aufwand klein.
- H2 `close()` setzt den Lauf auf PREVIEW_READY zurück, obwohl ein anderer offener Zahlungsvorgang existiert (`HandleStripeEvent.php:234`). Tritt bei Preisänderung während eines offenen Checkouts auf. Aufwand klein.
- H3 Abbruch oder Löschen eines Laufs in CHECKOUT_PENDING lässt die Stripe-Sitzung offen (`BillingRunController.php:215`). Spätere Zahlung wird verworfen. Aufwand klein.
- H4 Blockierte Betreiberrechnung kann nach Ergänzung der Stammdaten nicht nachgeholt werden (`FinalizeBillingRun.php:191`). FINALIZED ist Endzustand, es gibt keinen Aufrufer für die Rechnung. Aufwand mittel.
- H5 Korrektur nach Zahlung ist nicht verdrahtet (`CorrectionPriceRule.php:48`, `RecordCorrection.php:25`). Keine Route, kein Übergang aus FINALIZED. Die Korrekturfrist ist wirkungslos. Zwei Agenten unabhängig. Entscheidung: umsetzen oder als nicht verfügbar kennzeichnen. Aufwand groß bei Umsetzung.

**Statusmaschine und Wizard**
- H6 Zweiter Bestätigungsweg umgeht die Vorschauprüfung (`BillingRunController.php:189`). Route `abrechnungen.bestaetigen` setzt Zeitstempel ohne Prüfung, Checkout auf altem Snapshot möglich. Aufwand klein.
- H7 Vorschau nimmt unbestätigte KI-Positionen auf, Kostenprüfung ist überspringbar (`PreviewController.php:76`). Aufwand klein.
- H8 Änderungen an Kostenpositionen und Heizkosten machen Vorschau und Bestätigung nicht ungültig (`CostItemDecisions.php:80`). Nur Vorauszahlungen und Schlüssel invalidieren. Aufwand klein.

**Geld und Eingabe**
- H9 Personentage: Mietverhältnis ohne Personenabschnitte erhält Gewicht 0, die anderen tragen den Anteil (`BillingRunInputAssembler.php:586`). Aufwand klein.
- H10 Betragseingaben mit Punkt werden verhundertfacht, unlesbare Beträge werden still zu 0 oder verworfen (`UpdateCostItemRequest.php:76`, `StorePrepaymentsRequest.php:93`). Eingabe "1200.50" ergibt 120.050,00 EUR. Vier Meldungen zum selben Parser. Es existiert bereits `EuroAmountInput` mit korrekter Logik. Aufwand klein.

**KI und Konfiguration**
- H11 Leeres `AI_BIND_DOCUMENT_PIPELINE=` in `.env.example` schaltet die KI-Anbindung ab (`config/ai.php:68`). Leerer String ist nicht null. Aufwand klein.
- H12 Leeres `AI_MAX_DAILY_COST_CENT_PER_USER=` ergibt Tageslimit 0, jede Auswertung scheitert endgültig (`config/ai.php:72`). Aufwand klein.
- H13 Kostenbasis für die OpenAI-Modelle fehlt, Tagesbudget greift für den Standardprovider nie (`config/ai.php:110`). Aufwand klein.
- H14 Fehlgeschlagene Löschung einer Providerdatei wird weder persistiert noch wiederholt (`AiDocumentExtractor.php:103`, `AbstractHttpAiProvider.php:619`). `provider_file_id` wird produktiv nie geschrieben, Retry und Datenschutzmonitor laufen ins Leere. Zwei Agenten unabhängig. Aufwand mittel.

**Vollständigkeit gegen Masterprompt**
- H15 Vermieter als Absender kann im Portal nicht angelegt werden (`StatementViewFactory.php:117`). Kein Controller, keine View. Mieter-PDF zeigt nur die Objektbezeichnung. Aufwand mittel.
- H16 Extraktionsdaten aus Mietvertrag, Vorjahresabrechnung, Mieterliste, Zahlungsübersicht und Zählerliste werden nicht in Fachdaten überführt (`CostItemMapper.php:51`). Schemata existieren, Reconciliation nutzt nur Hausgeld, Grundsteuer, Heizkosten, Brennstoff, Rechnungen. Aufwand groß.
- H17 Gewerbliche Mietverhältnisse werden trotz Vorgabe automatisch finalisiert (`OccupancyTimeline.php:154`). Nur Hinweistexte, keine Sperre. Aufwand klein.

**Betrieb und Kommunikation**
- H18 Löschantrag ohne Benachrichtigung und ohne erneute Authentifizierung (`AccountDeletionWorkflow.php:111`). Aufwand klein.
- H19 SMTP-Ausfall oder falsches Passwort sperrt alle angeschriebenen Adressen dauerhaft (`MailDispatcher.php:163`). Regex `\b5\d\d\b` trifft Port 587 und Code 535. Keine Admin-Funktion zum Aufheben. Aufwand klein.
- H20 Kernschriftmodus von mPDF verliert Zeichen außerhalb Windows-1252 (`PdfEngine.php:134`). "Yılmaz" wird "Y?lmaz". Aufwand klein, UTF-8-Modus mit DejaVu.

## 4. Mittel (28) und Niedrig (21)

Mittel, Auswahl mit Nutzerwirkung:
- Admin-Middleware `verified` wirkungslos, User implementiert `MustVerifyEmail` nicht (`routes/web.php:80`).
- Negative Heizkostenbeträge führen zu HTTP 500 (`ManualHeatingReconciler.php:118`).
- Stornorechnung druckt "bereits vollständig entrichtet" (`hvm-rechnung.blade.php:204`).
- Anwendung läuft fest in UTC, `APP_TIMEZONE` wird ignoriert; Rechnungsdatum und Nummernkreisjahr am Jahreswechsel falsch (`config/app.php:70`).
- TOTP-Code im Toleranzfenster mehrfach verwendbar, kein Replay-Schutz (`TwoFactorAuthentication.php:165`).
- Passwort-Reset beendet fremde Sitzungen nicht; Bestätigungslink hebt eine Sperre auf; 2FA-Schritt prüft Kontostatus nicht.
- Datenexport im geteilten Mandanten für andere Mitglieder abrufbar und ohne Ratenbegrenzung (`DataExportBuilder.php:139`, `routes/portal.php:296`).
- Lease 300 s ohne Heartbeat kann Dokumente doppelt an den Provider senden.
- Deploy-Skript: Symlinks relativ zum SFTP-Home, halbes Release als Rollback-Ziel; `public/.htaccess` leakt `/current/public/` in Redirects.
- `.env.example` führt wirkungslose Variablen (SENTRY_*, S3_ENABLED, MAIL_ENCRYPTION).
- Objekt-Löschung mit laufenden Abrechnungen erzeugt Laufzeitfehler; Unique-Index units kollidiert mit Soft-Delete; `documents.sequence_number` ohne Sperre.
- Erinnerungsabmeldung ändert Zustand per GET; XLSX wird akzeptiert, aber nie ausgewertet; Bounce-Sperre ohne Hinweis im Konto.
- Verlorener Zweitfaktor: kein Wiederherstellungsweg für Kunden und Support.
- Mailversand synchron ohne Fehlerbehandlung, SMTP-Ausfall ergibt HTTP 500 bei Registrierung.
- Test zur Vorschau-Invalidierung umgeht den Anwendungsweg und verdeckt H8.

Niedrig: Folgejahresübernahme ohne Policy, Schlüsselwerte mit fremden IDs, signierter Download ohne Verifizierungs-Gate, Kontoexistenz über E-Mail-Änderung erkennbar, `payment_intent`-Ereignisse nicht zuordenbar, Rechnungsposition Anzahl × Einzelpreis stimmt um Cent nicht, Zahlungsseite 500 bei Preis außerhalb Korridor, Stripe-Fehler als Serverfehler, Tagesbudget wechselt in UTC, mPDF-Platzhalter in Nutzertexten, `check-config` löscht den Produktionscache, keine deutschen Fehlerseiten (404, 419, 500), englische Validierungsmeldungen, Cron-Intervall nicht angezeigt, Statusmails existieren, werden aber nie versendet, Analysefortschritt erreicht nie 100 Prozent bei abgelehnten Dokumenten, roher Ausnahmetext an Kunden, Sicherheitsheader fehlen auf 404 und 301, Testkennzahlen in README und ARCHITECTURE veraltet (2.008 statt 2.011).

Die vollständige Liste mit Fehlerszenario und Vorschlag je Punkt liegt maschinenlesbar in der Prüfdatei der Sitzung vor und kann bei Bedarf in dieses Dokument übernommen werden.

## 5. Ungeprüfte Meldungen (23)

Diese Meldungen stammen aus Runde 2 und konnten nicht gegengeprüft werden. Sie sind vor einer Behebung zu verifizieren.

- Blocker: Externe Heizkostenabrechnung (Fall A) erzeugt keine Kostenpositionen, WEG-Heizkosten werden trotzdem ausgeschlossen (`ReconcileBillingRun.php:82`).
- Hoch: Adminrollen ADMIN, SUPPORT, FINANCE haben identische Rechte; Verbrauchsschlüssel scheitert bei erfasstem Leerstand; Ersatzverteilung ohne Zwischenablesung nicht erreichbar; Soll-Vorauszahlung wird nach Änderung des Mietverhältnisses nicht neu berechnet; Folgejahresübernahme kopiert Verbrauchswerte als Zähler; Webhook-Insert-Fehler geht mit HTTP 200 verloren; Rechnung ohne Empfängeranschrift über 250 EUR; Integritätsfehler beim Zusammensetzen lässt Chunks bis zur TTL liegen.
- Mittel: Remember-Cookies umgehen die 2FA-Pflicht; Vorschau für unbestätigte Konten nicht abrufbar; Grundsteuerzeitraum stillschweigend gleichgesetzt; monatliche Vorauszahlung mit Tausenderpunkt um Faktor 1000 zu klein; Doppelklick im Checkout erzeugt zwei Sitzungen; `payment_failed` beendet einen noch bezahlbaren Vorgang; zweite Finalisierung setzt Rechnungs-PDF auf ERSETZT; Datenbankfehler im Keyring wird verschluckt; Chunk-Schreibpfad nicht atomar; `withDecryptedCopy` prüft `fwrite` nicht; Kontolöschung entkoppelt Rechnungen geteilter Mandanten; Kontolöschung löscht Kurzzeitdatensätze per Kaskade ohne Dateilöschung.
- Niedrig: SEPA-Erstmeldung als Abweichung protokolliert; interner Blockertext im Checkout sichtbar.

## 6. Widerlegte Meldungen (13)

| Meldung | Grund |
|---|---|
| Checkout-Abbruch per GET mit Leserecht | Verhalten existiert, aber keine Wirkung auf Geld oder Daten |
| Route-Model-Binding liefert 403 statt 404 | Keine Nutzerwirkung |
| Float in Preisanzeige Admin | Eingabe ist Integer, kein Geldpfad |
| Fehlgeschlagene Dateilöschung verliert Pfad | `ArtifactStorage::delete()` kann kein false liefern |
| Mitglied ohne OWNER kann Löschung nicht beantragen | Gewolltes Verhalten |
| Datenexport lädt alles in den Speicher | Zutreffend, aber bei den erwarteten Größen ohne Wirkung |
| Float in Preisseite Netto | Bei 19 Prozent kann kein Rundungsfall entstehen |
| Preis im Admin nicht konfigurierbar | Dokumentiert und gewollt (ENV) |
| Backupstatus fehlt im Admin | Kein Defekt, Betreiberentscheidung |
| Erinnerungstermine nur per ENV | Gewollt |
| Widersprüchliche Kennzahlen in Dokumenten | Zahlen stimmen, Deutung als Widerspruch trägt nicht |
| Folgejahr nur über signierten Link | Gewollt, dokumentiert |
| Cron-Ausfall im Admin nicht sichtbar | Vorhanden über CLI-Check, Ergänzung wäre Verbesserung |

## 7. Funktionslauf

| Prüfung | Ergebnis |
|---|---|
| Pint | bestanden |
| PHPStan Level 6 | keine Fehler |
| PHPUnit | 2.011 Tests, 12.679 Assertions, 3:02 min, keine Warnungen |
| Installation auf leerer SQLite | Exit 0, idempotent, 21 Migrationen, 31 Kategorien |
| admin:create | Exit 0, idempotent |
| check-config | Exit 1 mit 5 erwarteten Fehlern, Cache-Neustart genau einmal |
| Öffentliche Seiten | alle 200, Redirect auf https greift, Portalrouten ohne Login leiten um |

Hinweis: Nach `check-config` bleibt die Konfiguration ungecacht, bis `install` erneut läuft. In der Installationsanleitung ergänzen.

## 8. Was in Ordnung ist

Mandantentrennung der Portalrouten mit ID-Parameter ist durchgängig über Policies gelöst, es wurde kein IDOR mit Datenwirkung gefunden. Rundung nach Largest Remainder und taggenaue Zeitanteile sind korrekt. Verschlüsselung des Kurzzeitbereichs, HKDF und Blockgrenzen sind korrekt umgesetzt. Stripe-Signaturprüfung, Rechnungsnummernkreis und Netto/USt-Zerlegung stimmen. Wasserzeichen ist serverseitig und nicht per Parameter abschaltbar. Betreiberangaben sind an allen Stellen exakt. Kein Secret im Code, keine personenbezogenen Daten in Fixtures.

## 9. Offene Betreiberpunkte ohne Codebezug

Steuer- und Bankdaten mit `HVM_MASTERDATA_CONFIRMED`, Stripe-Schlüssel, KI-Freigabe und AVV, Rechtstexte durch Rechtsanwalt, Logo, Malware-Scanner, Korrekturfrist, Aufbewahrungsfristen, Staging mit MariaDB, SMTP, SFTP.

## 10. Empfohlene Reihenfolge

1. B6 (CSP Stripe), B8 (Klickpfad), B3, B4, B7, H10 (Betragsparser), H11 bis H13 (leere ENV-Werte): alles kleine Änderungen mit großer Wirkung, zusammen etwa ein Arbeitstag.
2. B1, B2, H9 (Verteilungslogik) mit Feature-Tests.
3. B5, B9, H1 bis H4 (Zahlungspfad robust machen: Vorbedingungen, Wiederanlauf, Webhook-Retry, Rechnung nachholen, Admin-Werkzeuge).
4. H6 bis H8 (Sperrmechanismus der Vorschau schließen).
5. H14, H17, H18, H19, H20 und die mittleren Sicherheitspunkte (TOTP-Replay, Sitzungen, Bestätigungslink).
6. Entscheidung Geschäftsführung zu H5 (Korrektur nach Zahlung), H15 (Vermieterdaten), H16 (Überführung weiterer Dokumentarten): umsetzen oder für den Start ausdrücklich ausschließen und in Doku, Preisseite und Livegang-Blockern kennzeichnen.
7. Verifikation der 23 ungeprüften Meldungen, dann zweiter Prüflauf für die Bereiche mit nur einer Runde.

## 11. Stand der Behebung (04.09.2026, abends)

Alle 85 bestätigten Befunde und alle 23 ungeprüften Meldungen wurden bearbeitet. Die 23 ungeprüften Meldungen wurden vor der Behebung im Code nachverifiziert; alle 23 erwiesen sich als real, darunter der Blocker zur externen Heizkostenabrechnung (Fall A). Kein Befund wurde als widerlegt zurückgegeben.

Vorgehen: acht Arbeitspakete nach Dateizuständigkeit (Geld und Verteilung, Betragseingaben, Zahlung und Webhook, Wizard und Klickpfad, Betrieb und Konfiguration, KI und Kurzzeitbereich, Konto und Datenschutz, Vermieterdaten), je Paket eine eigene Git-Arbeitskopie, je Befund ein Regressionstest auf dem Anwendungsweg, danach Zusammenführung mit drei aufgelösten Konflikten und ein Gesamtlauf.

| Prüfung nach Zusammenführung | Ergebnis |
|---|---|
| Pint | bestanden |
| PHPStan Level 6 | keine Fehler |
| PHPUnit | 2.279 Tests, 14.253 Assertions (vorher 2.011 Tests) |

Drei Punkte wurden nicht als Funktion umgesetzt, sondern für den Start bewusst ausgeschlossen und überall ehrlich gekennzeichnet: Korrektur nach Zahlung (ADR-016), XLSX-Auswertung (ADR-017), Überführung von Mietvertrag, Vorjahresabrechnung, Mieterliste, Zahlungsübersicht und Zählerliste in Fachdaten (ARCHITECTURE.md Abschnitt 11.1). Vermieterdaten als Absender wurden umgesetzt.

Aus der Behebung neu bekannte, noch offene Punkte stehen in ARCHITECTURE.md Abschnitt 11 (Personentage bei Leerstand, Dezimaleingabe bei Direktzuordnung, Lease-Inhaber der Jobwarteschlange, Sperre beim Entpacken von Archiven, Notfallbefehl für den Zweitfaktor).

Entscheidungen, die die Geschäftsführung bestätigen sollte, sind in Abschnitt 12 gesammelt.

## 12. Vorlagen für die Geschäftsführung aus der Behebung

1. Steuerzerlegung der Betreiberrechnung: Netto und Umsatzsteuer werden jetzt je Einzelpreis zurückgerechnet und summiert, nicht aus der Bruttosumme. Das verschiebt bei mehreren Abrechnungen einzelne Cent zwischen Netto und Steuer. Mit dem Steuerberater bestätigen.
2. Zahlungseingang ohne freischaltbaren Lauf (Abbruch, Löschung, geänderter Berechnungsstand): Das System hält die Zahlung fest und zeigt sie unter Zahlungsnachlauf im Adminbereich. Erstattung oder Zuordnung wird je Fall kaufmännisch entschieden. Bei unverändertem Berechnungsstand nach Nutzerabbruch wird der Lauf freigeschaltet und die Leistung geliefert.
3. Rechnungsanschrift ist für jeden Checkout Pflicht, nicht nur oberhalb von 250 EUR.
4. Finalisierung und Rechnung werden alle 15 Minuten automatisch nachgeholt, die Rechnungsmail wird dabei automatisch gesendet.
5. Gewerbliche Mietverhältnisse sind technisch gesperrt, bis eine Gewerbeumsetzung erfolgt. Hinweistext freigeben.
6. Kalkulationsbasis für die OpenAI-Modelle in config/ai.php aus der offiziellen Preisliste eintragen, sonst meldet check-config bei gesetztem Tageslimit einen Fehler. Alternativ Anthropic als Primärprovider oder Betrieb ohne Tageslimit.
7. Höhe des Tagesbudgets je Nutzer festlegen oder bewusst ohne Limit betreiben.
8. Anwendungszeitzone Europe/Berlin: Produktionsdatenbank vor dem Livegang frisch aufsetzen.
9. Vor Livegang manueller Browserlauf des Bezahlschritts gegen Stripe im Testmodus in Chrome und Safari, weil die browserseitige CSP-Prüfung nicht per HTTP-Test abbildbar ist.
10. Betriebliche Festlegungen bestätigen: 3 Datenexporte je Nutzer und Tag, nur der jüngste Export wird bereitgehalten; Erinnerung 5 Tage vor Kontolöschung; Löschantrag verlangt das aktuelle Passwort; Verarbeitungsfehler-Mail gilt als kritische Kontonachricht und geht auch an abgemeldete Adressen.
11. Rechtematrix der Adminrollen: SUPPORT darf Kundenkonten sperren und Zweitfaktor zurücksetzen, aber keine Stornorechnungen und keine internen Kennungen anfassen; FINANCE darf Stornorechnungen, aber keine Nutzerverwaltung. Ob FINANCE Supportzugriff auf Kundendaten braucht, ist offen.
12. Objekte mit abgeschlossenen oder abgebrochenen Läufen sind nicht mehr löschbar. Ob ein Archivierungsweg gewünscht ist, ist zu entscheiden.
13. Sollsumme der Vorauszahlungen bei angebrochenen Monaten wird taggenau innerhalb des Monats gebildet. Falls die volle Monatsrate ab Einzug gelten soll, ist das anzupassen.
14. Unbestätigte Vorjahresschlüssel aus der Folgejahresübernahme blockieren Schritt 8 bis zur Bestätigung.
15. Externe Heizkostenanteile (Fall A) werden als Vorschläge mit Kategorie Heizung erzeugt, ohne Trennung Heizung und Warmwasser. Bei gewünschter Trennung ist das Extraktionsschema zu erweitern.
16. Bestandsnutzer mit offenen Läufen ohne Vermieter müssen den Vermieter nachtragen, bevor Vorschau und Zahlung möglich sind.

## 13. Adversariale Nachprüfung der Behebungen und zweite Runde

Jede Behebung wurde von zwei unabhängigen Prüfern je Cluster (Fable 5.1 mit Linse Korrektheit, Opus 5 mit Linse Unabhängigkeit) am zusammengeführten Stand gegengeprüft: Ist der Defekt auf dem Anwendungsweg beseitigt, gibt es einen Regressionstest, der vorher fehlgeschlagen wäre, und hat die Behebung etwas Neues eingeführt.

Ergebnis: 100 von 108 Behebungen bestätigt, 8 unvollständig. Vier gravierende Folgepunkte, darunter drei durch die Behebung selbst eingeführt oder freigelegt:

| Folgepunkt | Schwere | Ursache |
|---|---|---|
| Folgejahresübernahme: wertloser Vorjahresschlüssel lässt die Berechnung scheitern | Blocker | Behebung U8 kopierte keine Werte mehr, der Assembler baute aber weiterhin jeden Schlüssel |
| Datenexport eines Mitglieds über die Downloadroute für andere Mandantenmitglieder abrufbar | Blocker | Behebung B48 filterte nur die Datenschutzseite, nicht den Downloadcontroller |
| Deployskript: relative Symlink-Ziele brechen nach dem Umschalten nach current | Blocker | Behebung B52 machte die Symlink-Probe erstmals erfolgreich und legte den Fehler frei |
| Stammdatenänderungen (Einheit, Mietverhältnis, Vermieter) setzen die Vorschau nicht zurück, Checkout ohne gültige Vorschau möglich | hoch | dieselbe Fehlerklasse wie B21, über andere Controller |

Dazu 35 Punkte mittlerer und niedriger Schwere. Alle 39 Folgepunkte wurden in einer zweiten Runde in fünf Arbeitspaketen behoben (Geld, Vorschau und Stammdaten, Konto und Rollen, Betrieb und Zahlung, KI und Kurzzeitbereich), jeweils mit Regressionstest. Zusammenführung ohne Konflikte, zwei Wechselwirkungen zwischen Paketen nachgezogen (Rollen-Gate der neuen Mailwiederholungsroute, Dokumentzählung im Zahlungsfixture).

| Prüfung nach Runde 2 | Ergebnis |
|---|---|
| Pint | bestanden |
| PHPStan Level 6 | keine Fehler |
| PHPUnit | 2.384 Tests, 14.911 Assertions (nach Runde 3 und Abschlussprüfung: 2.407 Tests, 15.075 Assertions) |

Wesentliche Änderungen der Runde 2 mit Betriebswirkung:
- Der Checkout verlangt jetzt eine gültige Vorschau und keine offenen Sperrgründe; jede Stammdatenänderung setzt Vorschau und Bestätigung zurück.
- Personentage-Schlüssel sind bei nicht durchgehend vermieteten Einheiten ein Blocker (keine Schätzung von Leerstandspersonen).
- Eine gelöschte Einheit löscht ihre Mietverhältnisse mit; Wiederanlegen erzeugt eine neue Einheit.
- Fehlgeschlagene Mails werden bis zu 24 Stunden automatisch wiederholt und können im Adminbereich erneut gesendet werden.
- Die Rechtematrix der Adminrollen ist vollständig: Zahlungsnachlauf ADMIN und FINANCE, Sperrliste und Mailwiederholung ADMIN und SUPPORT.
- Anwendungszeitzone Europe/Berlin ist als fachliche Konstante verankert, Rechnungsdatum und Nummernkreis hängen nicht mehr an APP_TIMEZONE.
- Neuer Notfallbefehl `smartabrechnen:admin:reset-2fa` mit dokumentiertem Verfahren.
- Website, FAQ, Uploaddialog und Abschlussmail versprechen keine Übernahme aus Mietvertrag, Vorjahresabrechnung oder Zahlungsübersicht mehr.

Verbleibende offene Punkte stehen in ARCHITECTURE.md Abschnitt 11; die neuen Vorlagen für die Geschäftsführung in Abschnitt 14.

## 14. Weitere Vorlagen für die Geschäftsführung aus Runde 2

1. Rechtematrix: Zahlungsnachlauf (Finalisierung und Rechnung nachholen) für ADMIN und FINANCE, Sperrlistenaufhebung und Mailwiederholung für ADMIN und SUPPORT. Bestätigen.
2. Adminbereich verlangt je Browsersitzung einmal den Zweitfaktor, auch bei "angemeldet bleiben". Bestätigen.
3. Notfallverfahren Zweitfaktor-Reset der einzigen Adminkennung (Rückruf, zweite Person, Ticket) als Betriebsanweisung freigeben.
4. Wiederholungspuffer für fehlgeschlagene Mails hält den vollständigen Inhalt inklusive signierter Downloadlinks bis zu 24 Stunden verschlüsselt. Bestätigen oder Fenster verkürzen.
5. Providerlöschung mit Antwort 404: als bestätigte Löschung werten oder Dokument blockieren lassen.
6. Eigene Tabelle für offene Providerdateien vor Livegang nachziehen (Empfehlung der Umsetzung).
7. Personentage bei Leerstand: soll der Schlüssel dort nutzbar sein, braucht der Leerstand ein Personenfeld. Entscheiden.
8. Entfernte Einheit mit Abrechnungsbezügen bleibt als umbenannte, gelöschte Zeile erhalten (Nachvollziehbarkeit). Bestätigen.
9. Fehlende Rechnungsanschrift nach dem Checkout: Leistung wird bereitgestellt, Rechnung wartet im Zahlungsnachlauf. Ob der Kunde aktiv zur Ergänzung aufgefordert wird, entscheiden.
10. Preiskorridor 20,00 bis 30,00 EUR ist Livegang-Blocker; Preisänderungen außerhalb erfordern Konfigurationsänderung.
11. Redaktionelle Freigabe der geänderten Website-, FAQ-, Upload- und Mailtexte.
12. Bei aktivem KI-Tagesbudget ohne Kalkulationsbasis stehen KI-Aufrufe still. Vor Livegang Basis für alle Modelle pflegen oder ohne Limit betreiben.

## 15. Dritte Nachprüfung und Runde 3

Die Änderungen der Runde 2 wurden erneut von zwei Prüfern je Cluster gegengeprüft (10 Prüfer). Ergebnis: alle 39 Punkte bestätigt behoben, keine Blocker und keine hohen Folgepunkte mehr, 14 mittlere Restpunkte. Diese wurden in Runde 3 in drei Arbeitspaketen behoben:

- Geld und Masken: Ablesesumme über dem Jahreswert wird Prüfaufgabe statt stillem Nullrest; negative Direktzuordnungsbeträge werden abgewiesen; die Maske in Schritt 8 weist Direktzuordnungen als Eurobetrag aus; das endgültige Entfernen einer Einheit löst keine Direktzuordnung mehr still auf, sondern setzt betroffene Positionen auf prüfpflichtig; Ist-Werte je Vorauszahlungszeile bleiben erhalten; der Personentage-Blocker erscheint bereits in Schritt 8; Website-Texte zu Vorjahresabgleich und Leerstand korrigiert.
- Zahlung und Mail: Die Invalidierung einer Vorschau bricht offene Zahlungsvorgänge ab und setzt den Lauf zurück; der Webhook schaltet nur bei gültiger Vorschau und vorliegender Bestätigung frei, sonst landet der Eingang als VORSCHAU_UNGUELTIG im Zahlungsnachlauf; Relay- und Absenderablehnungen mit genannter Empfängeradresse sperren nicht mehr; wiederholte Mails erhalten neu signierte Downloadlinks.
- Konto und KI: Rechtsnachweise und Exportmetadaten im Datenexport sind auf den Antragsteller begrenzt; der Provider prüft vor jedem Dateiupload, ob eine Providerdatei offen ist; der Verbrauch eines Primäraufrufs bleibt auch bei abgebrochenem Zweitaufruf nachgewiesen; endgültig fehlgeschlagene Dokumente tragen keine Wiederholungszusage mehr.

| Prüfung nach Runde 3 | Ergebnis |
|---|---|
| Pint | bestanden |
| PHPStan Level 6 | keine Fehler |
| PHPUnit | 2.405 Tests, 15.067 Assertions |

Weitere Vorlagen für die Geschäftsführung aus Runde 3:
1. Zahlungseingänge mit failure_code VORSCHAU_UNGUELTIG erscheinen im Zahlungsnachlauf; Erstattung oder Zuordnung ist je Fall zu entscheiden. Bis zur Erstattung im Stripe-Konto (Webhook charge.refunded gibt den Lauf wieder frei) kann der Kunde den korrigierten Lauf nicht erneut bezahlen und wird auf den Support verwiesen. Eine Aktion im Zahlungsnachlauf (Erstattung anstoßen oder kaufmännisch erledigen, mit Freigabevermerk) wäre eine sinnvolle Ergänzung; ob sie vor dem Livegang gebaut wird, entscheidet die Geschäftsführung.
2. Der Vermerk "Zieleinheit entfernt" an einer Kostenposition ist im Revisionsprotokoll und in manual_overrides nachvollziehbar, in der Prüfoberfläche erscheint die Position als offen. Eine sichtbare Anzeige des Vermerks wäre eine Ergänzung.
3. Formulierungen auf Startseite, Ablauf und FAQ zu Personentage bei Leerstand und zum Schlüsselvorschlag freigeben.
4. Rechtsnachweise ohne Personenzuordnung (Altbestand) erscheinen in keinem Datenexport.

Die zwei Abschlussprüfer der Runde 3 bestätigten alle drei geldrelevanten Punkte als behoben; ihre drei Restbeobachtungen (Ablesekonflikt bei Leerstand bereits in Schritt 8, bezahlte Läufe im Status FAILED beim Entfernen einer Einheit unangetastet, verworfene Positionen bleiben verworfen) wurden direkt umgesetzt und getestet.

Empfehlung: Der Code ist damit aus Sicht dieser Prüfung reif für den Staging-Lauf mit echten Diensten (MariaDB, SMTP, SFTP, Stripe-Testmodus, KI-Anbieter) und für Ihren Browsertest. Die Betreiberpunkte aus Abschnitt 9 und die Vorlagen aus den Abschnitten 12, 14 und 15 bleiben offen.
