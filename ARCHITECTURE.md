# Architektur — Nebenkostenabrechnungs-Portal (Hausverwaltung Müller GmbH)

Stand: Fundament aus Phase 1, Schritt 1 (Projektgerüst). Dieses Dokument
beschreibt Tech-Stack-Entscheidungen, Ordnerstruktur, Datenmodell und den
Phasenplan. Es ersetzt keine juristische, steuerliche oder sicherheitstechnische
Prüfung vor Livegang (siehe Abschnitt "Vor Livegang").

## 1. Tech-Stack

| Bereich | Wahl | Begründung |
|---|---|---|
| Framework | Next.js (App Router), TypeScript | Server Actions für Mutationen ohne separates API-Layer, gute DX, ein Deploy-Artefakt für UI + Backend. |
| Styling | Tailwind CSS v4 | CI-Farben als Design-Tokens (siehe `src/app/globals.css`, `tailwind.config.ts`); Utility-first passt zu wiederkehrenden Formular-/Tabellen-Layouts des Wizards. |
| ORM/DB | Prisma + MySQL 8 | Typsichere Queries, Migrationen, in der Hausverwaltungs-IT verbreitete DB-Engine. `DATABASE_URL` ausschließlich aus `.env`. |
| Auth | Auth.js (E-Mail + Passwort, Argon2id) | Etabliertes Framework für Next.js, Argon2id als aktuell empfohlener Passwort-Hash (OWASP). In diesem Schritt nur Grundgerüst (`src/lib/auth/config.ts`), volle Anbindung folgt in Phase 1. |
| Storage | Eigenes Interface (`src/lib/storage/interface.ts`), Default lokal, optional S3 | Entkoppelt Anwendungscode vom Speicherort; ermöglicht späteren Wechsel/Parallelbetrieb ohne Refactoring der Fachlogik. |
| KI | `@anthropic-ai/sdk` | Extraktion von Belegdaten (Kostenpositionen, externe Heizkostenabrechnungen) und Analyse-Funktionen. Siehe Abschnitt 3 zu Compliance. |
| PDF | Playwright/Chromium + `pdf-lib` | Siehe Abschnitt 4. |
| Zahlungen | Stripe, `@paypal/paypal-server-sdk` | Marktübliche Anbieter für Kartenzahlung bzw. PayPal; beide bieten Webhook-Verifizierung (siehe `src/app/api/webhooks/*`). |
| E-Mail | nodemailer/SMTP | Anbieterunabhängig, funktioniert mit jedem SMTP-Relay (Transaktionsmails: Verifizierung, Reset, Benachrichtigungen). |
| Jobs | DB-basierte Queue | Siehe Abschnitt 5. |
| Logging | pino | Strukturiertes JSON-Logging, geringer Overhead, gut für spätere Log-Aggregation. |
| Validierung | zod | Laufzeitvalidierung von Formulareingaben, Server-Action-Inputs und KI-Extraktionsergebnissen. |

## 2. PDF-Ansatz: Playwright vs. @react-pdf/renderer

**Entscheidung:** Playwright/Chromium für das Layout-Rendering, `pdf-lib` für
Nachbearbeitung (z.B. Metadaten setzen, mehrere PDFs zusammenführen).

**Begründung:**
- Abrechnungs-PDFs sollen optisch dem HVM-Briefbogen (CI-Farben, Kennlinie,
  Wasserzeichen, Falz-/Lochmarken nach DIN 5008) entsprechen. Mit
  Playwright lässt sich dasselbe HTML/CSS-Template wie im Web-UI (Tailwind-
  Tokens) für den PDF-Export wiederverwenden, statt ein zweites Layout-System
  gesondert pflegen zu müssen.
- Komplexe Tabellen (Kostenaufstellung je Einheit, Vorjahresvergleich) lassen
  sich mit CSS/Flexbox/Grid einfacher und wartbarer umsetzen als mit dem
  eigenen Layout-Modell von `@react-pdf/renderer`.

**Abgewogene Alternative:** `@react-pdf/renderer` wurde geprüft. Vorteile
wären ein reiner Node-Prozess ohne Chromium-Abhängigkeit (kleinere
Container, kein Sandboxing-Aufwand) und React-Komponenten statt HTML-Strings.
Nachteile: eigenes, von CSS abweichendes Layout-Modell (Yoga/Flexbox-Subset),
doppelte Pflege von Design-Regeln gegenüber dem Web-UI, eingeschränktere
Typografie-/CSS-Feature-Unterstützung. Für die angestrebte CI-Treue überwiegt
der Playwright-Ansatz; `@react-pdf/renderer` bleibt als Fallback dokumentiert,
falls sich der Chromium-Betrieb im Zielsystem (Docker/Ressourcen) als zu
aufwendig erweist.

## 3. KI-Nutzung (Anthropic) — Compliance-Hinweis

Vor produktivem Einsatz der KI-Extraktion/-Analyse:
- Auftragsverarbeitungsvertrag (AVV/DPA) mit Anthropic muss vorliegen.
- Zu prüfen und zu dokumentieren: Zero-Data-Retention-Vereinbarung, bevor
  personenbezogene Daten (Mieter-/Eigentümerdaten, Belege) übermittelt werden.
- Modellwahl ausschließlich über `ANTHROPIC_MODEL_EXTRACT` /
  `ANTHROPIC_MODEL_ANALYZE` (`.env`), keine hartkodierten Modellnamen.
- Nutzung wird pro Nutzer limitiert (`AI_DAILY_LIMIT_PER_USER`) und im Modell
  `AiUsageLog` protokolliert (Kosten-/Missbrauchskontrolle).

Siehe `src/lib/ai/client.ts` für den entsprechenden Code-Kommentar.

## 4. Datenmodell (Überblick)

Vollständiges Schema: `prisma/schema.prisma`. Kernentitäten:

- **User** — Portal-Benutzer (Rollen: Admin, Hausverwaltung, Eigentümer, Mieter).
- **Property / Unit** — Objekt (WEG/Mietshaus) und dessen Einheiten.
- **Tenancy / TenancyOccupancy** — Mietverhältnis und zeitraumbezogene
  Belegung (für taggenaue Umlage bei unterjährigen Änderungen).
- **BillingRun** — ein Abrechnungslauf für ein Objekt und eine Periode.
- **CostCategory / AllocationKey / CostItem** — Kostenarten, Umlageschlüssel
  (Wohnfläche, Personen, MEA, Verbrauch, Einheiten, direkt, individuell) und
  die einzelnen Kostenpositionen eines Laufs.
- **ExternalHeatingStatement** — importierte externe Heizkostenabrechnung
  (z.B. Techem/Ista), inkl. KI-Extraktionsrohdaten.
- **UnitStatement** — die Einzelabrechnung je Einheit/Mieter mit Saldo und
  nachvollziehbarem Rechenweg (`calculationDetails`, JSON).
- **Document** — alle Belege/PDFs, referenziert einen Storage-Key.
- **Payment / Invoice** — Zahlungen und Rechnungen für Portalgebühren.
- **AiUsageLog** — Protokoll der KI-Nutzung je Nutzer.
- **AuditLog** — Nachvollziehbarkeit sicherheitsrelevanter/fachlicher Aktionen.

Alle Geldbeträge sind `Int` in Cent. Status-Felder sind als Enums modelliert.

## 5. Job-Queue

Bewusst **keine** Redis-Abhängigkeit in Phase 1. Vorgesehenes Muster: eine
Datenbanktabelle (Payload als JSON, Status, `attempts`, `runAt`, `lockedAt`),
die vom `worker`-Service (siehe `docker-compose.yml`) per Polling abgearbeitet
wird. Das konkrete Job-Modell wird ergänzt, sobald der erste asynchrone
Anwendungsfall (z.B. PDF-Erzeugung für einen kompletten Abrechnungslauf)
ansteht — Redis/BullMQ o.ä. kann bei Bedarf später ergänzt werden, ist aber
für den Start nicht erforderlich.

## 6. Ordnerstruktur

```
src/
  app/
    (auth)/           Login, Registrierung, Verifizierung, Passwort-Reset
    (dashboard)/      Objekte, Abrechnungsläufe (spätere Wizard-Schritte)
    (legal)/          AGB, Datenschutz, Impressum, Widerruf (Platzhalter)
    api/webhooks/     Stripe- und PayPal-Webhook-Endpunkte
  lib/
    auth/             Auth.js-Grundgerüst, Argon2id-Hashing
    ai/               Anthropic-Client, Zod-Extraktionsschemata
    calc/             Berechnungs-Engine (Umlage), mit Unit-Tests
    db.ts             Prisma-Client-Singleton
    email/            nodemailer/SMTP
    payments/         Stripe, PayPal
    pdf/              PDF-Erzeugung (Playwright + pdf-lib)
    queue/            DB-basierte Job-Queue (Konzept)
    storage/          Storage-Interface + lokale/S3-Implementierung
  components/         Geteilte UI-Komponenten (CI-Tokens-Beispiel)
tests/                Vitest-Tests (u.a. für die Berechnungs-Engine)
prisma/schema.prisma  Vollständiges Datenmodell
public/ci/            CI-Assets (vom Auftraggeber bereitzustellen)
```

## 7. Geplante Phasen (Kurzüberblick)

- **Phase 1 (aktuell):** Fundament (dieser Schritt), Auth.js voll anbinden,
  Abrechnungs-Wizard Schritt 1–7 (Objekt/Periode wählen, Kosten erfassen,
  Umlageschlüssel, externe Heizkostenabrechnung importieren, Berechnung,
  Prüfung/Freigabe, Versand), Berechnungs-Engine mit vollständiger
  Testabdeckung aller Umlagearten.
- **Phase 2:** KI-gestützte Beleg-/Heizkostenextraktion produktiv,
  PDF-Erzeugung im HVM-Layout, Zahlungs-Integration (Stripe/PayPal),
  E-Mail-Versand der fertigen Abrechnungen, Storage produktiv (lokal/S3).
- **Phase 3:** Mieter-/Eigentümer-Self-Service (Einsicht, Zahlungsabgleich),
  Reporting/Auswertungen, Rechtstexte final freigegeben, Monitoring
  (Sentry) und Betriebs-Hardening.

## 8. Offene Punkte — Vor Livegang

- **Rechtstexte:** Impressum, Datenschutzerklärung, AGB, Widerrufsbelehrung
  in `src/app/(legal)/*` sind Platzhalter-Strukturen ohne geprüften
  Rechtsinhalt. Zwingend durch einen Rechtsanwalt erstellen/prüfen und
  freigeben lassen, bevor die Seiten öffentlich zugänglich sind.
- **Firmendaten in `.env`:** `HVM_TAX_ID`, `HVM_VAT_ID`, `HVM_IBAN`,
  `HVM_BIC` sind in `.env.example` bewusst leer. Vor Livegang mit den
  tatsächlichen, geprüften Werten befüllen (nur in `.env`, niemals im Code
  oder in Dokumenten hartkodieren).
- **CI-Assets:** `public/ci/` enthält keine echten Dateien. Logo
  (`Logo_HVM.jpg` o.ä.) und Unterschrift sind vom Auftraggeber
  bereitzustellen; es wurde bewusst kein Logo generiert.
- **AVV/DPA und Datenschutz-Folgenabschätzung** für Anthropic (KI),
  Stripe/PayPal (Zahlungen) und den gewählten Storage-Anbieter (falls S3)
  vor produktivem Einsatz klären.
- **Auth.js-Sicherheitshärtung:** Rate-Limiting für Login/Reset,
  E-Mail-Verifizierungs-Flow, Session-Konfiguration vor Livegang final
  festlegen und prüfen.
- **Preise:** `PRICE_BASE_NET_CENT` / `PRICE_PER_UNIT_STATEMENT_NET_CENT`
  und `VAT_RATE` sind aktuell Platzhalter (0 bzw. 19 %) und mit der
  Geschäftsführung final abzustimmen.
- **Backups/Monitoring:** Datenbank-Backup-Strategie und `SENTRY_DSN`
  produktiv einrichten.
