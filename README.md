# NebenkostenKI

Nebenkostenabrechnungs-Portal der Hausverwaltung Müller GmbH. Next.js
(App Router, TypeScript, Server Actions), Prisma/MySQL 8, Tailwind CSS mit
HVM-CI-Design-Tokens.

Der aktuelle Stand ist das **Fundament aus Phase 1, Schritt 1**: Projektgerüst,
Datenmodell, Ordnerstruktur und Platzhalter-Module für die spätere
Implementierung. Details zu allen Architekturentscheidungen, dem vollständigen
Datenmodell und dem Phasenplan: siehe **[ARCHITECTURE.md](./ARCHITECTURE.md)**.

## Setup

Voraussetzungen: Node.js 20+, npm, Zugriff auf eine MySQL-8-Instanz (lokal
oder extern).

```bash
npm install
cp .env.example .env
# .env mit den tatsächlichen Werten befüllen (siehe Referenz unten)

npx prisma generate
npx prisma validate
# sobald eine MySQL-Instanz erreichbar ist:
npx prisma migrate dev

npm run dev
```

Weitere Skripte:

```bash
npm run build       # Produktions-Build
npm run lint         # ESLint
npm run typecheck    # TypeScript ohne Emit
npm run test         # Vitest (u.a. Berechnungs-Engine)
```

Optional per Docker Compose (App, Worker, MySQL): `docker-compose.yml` im
Repo-Root. MySQL kann auch extern/managed betrieben werden, siehe
Kommentare in der Datei.

## Umgebungsvariablen

Alle benötigten Variablen sind in **[.env.example](./.env.example)**
dokumentiert (Datenbank, Auth.js, Anthropic/KI, Storage, Stripe, PayPal,
Preise, SMTP, HVM-Firmendaten, Sentry). `.env` wird niemals committet.

## Vor Livegang — Checkliste (Kurzfassung)

Details und Begründungen siehe [ARCHITECTURE.md](./ARCHITECTURE.md#8-offene-punkte--vor-livegang).

- [ ] Rechtstexte (Impressum, Datenschutz, AGB, Widerruf) von einem
      Rechtsanwalt erstellen/prüfen und freigeben lassen.
- [ ] `.env`-Firmendaten (`HVM_TAX_ID`, `HVM_VAT_ID`, `HVM_IBAN`, `HVM_BIC`)
      mit geprüften echten Werten befüllen.
- [ ] Offizielle CI-Assets (Logo, Unterschrift) in `public/ci/` ablegen
      (vom Auftraggeber bereitzustellen, nicht generiert).
- [ ] AVV/DPA mit Anthropic (KI), Stripe/PayPal (Zahlungen) und ggf.
      S3-Anbieter (Storage) klären.
- [ ] Auth.js-Sicherheitshärtung (Rate-Limiting, E-Mail-Verifizierung,
      Session-Konfiguration) abschließen.
- [ ] Portal-Preise und Umsatzsteuersatz final mit der Geschäftsführung
      abstimmen.
- [ ] Datenbank-Backup-Strategie und Monitoring (`SENTRY_DSN`) produktiv
      einrichten.

## Status / nächste Schritte in Phase 1

- Auth.js voll anbinden (Login/Registrierung/Verifizierung/Reset).
- Abrechnungs-Wizard Schritt 1–7 implementieren.
- Berechnungs-Engine (`src/lib/calc/`) um alle Umlagearten erweitern und mit
  vollständiger Testabdeckung versehen.
