# Architekturentscheidungen (ADR)

Die tragenden Entscheidungen stehen gebündelt in
[../../ARCHITECTURE.md](../../ARCHITECTURE.md), Abschnitt 3. Dieser Ordner nimmt
ergänzende Einzelentscheidungen auf, die dort zu ausführlich wären, zum Beispiel
die Detailbegründung einer Bibliotheksauswahl oder eine verworfene Alternative
mit Messwerten.

## Bestehende Entscheidungen

| Nr. | Entscheidung | Ort |
| --- | --- | --- |
| ADR-001 | Eine Anwendung, klar getrennte Schichten | ARCHITECTURE.md |
| ADR-002 | Öffentliches Frontend und Anwendung in einer Laravel-Instanz | ARCHITECTURE.md |
| ADR-003 | MariaDB-Connection statt MySQL-Connection | ARCHITECTURE.md |
| ADR-004 | `brick/math` statt bcmath für die Dezimalarithmetik | ARCHITECTURE.md |
| ADR-005 | mPDF statt Headless Chromium | ARCHITECTURE.md |
| ADR-006 | Datenbankgestützte Queue mit Cron-getriebenen kurzen Läufen | ARCHITECTURE.md |
| ADR-007 | Originaldateien sind Kurzzeitdaten, nicht Bestandsdaten | ARCHITECTURE.md |
| ADR-008 | Providerabstraktion mit REST-Client statt Provider-SDK | ARCHITECTURE.md |
| ADR-009 | Modellwahl und Modellnamen | ARCHITECTURE.md |
| ADR-010 | Preise werden brutto angezeigt und serverseitig neu berechnet | ARCHITECTURE.md |
| ADR-011 | Eigene Zählertabelle für den Rechnungsnummernkreis | ARCHITECTURE.md |
| ADR-012 | Statusfortschritt als eigener Dienst, nicht als Sitzungszustand | ARCHITECTURE.md |
| ADR-013 | Final-PDFs entstehen auf demselben Renderweg wie die Vorschau | ARCHITECTURE.md |
| ADR-014 | Heizkostenfall B wird manuell erfasst, nicht selbst gerechnet | ARCHITECTURE.md |
| ADR-015 | Zweitfaktor selbst implementiert, ohne neue Abhängigkeit | ARCHITECTURE.md |
| ADR-016 | Korrektur nach Zahlung ist zum Start nicht verfügbar | ARCHITECTURE.md |
| ADR-017 | XLSX wird zum Start nicht ausgewertet | ARCHITECTURE.md |
| ADR-018 | Anwendungszeitzone Europe/Berlin | ARCHITECTURE.md |

## Vorlage für eine neue Entscheidung

Dateiname: `ADR-0NN-kurzer-titel.md`

```markdown
# ADR-0NN: Titel

**Status:** vorgeschlagen | entschieden | ersetzt durch ADR-0MM
**Datum:** TT.MM.JJJJ
**Betrifft:** betroffene Schicht oder Komponente

## Kontext

Welches Problem liegt vor, welche Randbedingungen gelten (IONOS Profil A,
Datenschutzkonzept, Reproduzierbarkeit bezahlter Berechnungsstände)?

## Entscheidung

Was wird festgelegt, in einem Satz.

## Begründung

Warum diese Variante, gemessen an Wirtschaftlichkeit, Risiko, Aufwand und
Umsetzbarkeit.

## Bewertete Alternativen

| Alternative | Bewertung | Grund der Ablehnung |
| --- | --- | --- |

## Konsequenzen

Was folgt daraus für Code, Tests, Betrieb und Dokumentation, einschließlich der
Punkte, die dadurch prüfpflichtig bleiben.
```
