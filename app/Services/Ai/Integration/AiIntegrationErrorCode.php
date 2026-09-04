<?php

declare(strict_types=1);

namespace App\Services\Ai\Integration;

use App\Services\Storage\UploadErrorCode;

/**
 * Fehlercodes der Adapterschicht zwischen Dokumentpipeline und KI-Schicht.
 *
 * Die Anwendungsschicht kennt ausschliesslich App\Services\Storage\UploadErrorCode.
 * Dieser Aufzaehlungstyp uebersetzt die technischen Abbruchgruende der
 * KI-Anbindung in genau diese Codes, damit documents.failure_code und
 * documents.failure_message weiterhin aus einer einzigen Quelle stammen.
 *
 * Nur fuer das ausgeschoepfte Tagesbudget gibt es keinen passenden
 * Uploadfehlercode. Dieser Fall traegt deshalb einen eigenen Code, den die
 * Pipeline auf EXTRAKTION_FEHLGESCHLAGEN abbildet. Die praezise deutsche
 * Meldung steht in message() und wird im Nachweis und im Protokoll gefuehrt.
 * Offener Punkt fuer den Eigentuemer von app/Services/Storage: einen eigenen
 * Fall KI_TAGESLIMIT_ERREICHT in UploadErrorCode ergaenzen.
 *
 * DATENSCHUTZ: Kein Code und keine Meldung enthaelt Dateiinhalte, Dateinamen,
 * Fundstellen, Prompts oder Providerantworten.
 */
enum AiIntegrationErrorCode: string
{
    /** Die Quelldatei liegt nicht mehr im Kurzzeitbereich. */
    case QUELLE_NICHT_LESBAR = 'QUELLE_NICHT_LESBAR';

    /** Fuer die erkannte Dokumentart ist kein Extraktionsschema hinterlegt. */
    case KEIN_SCHEMA_FUER_DOKUMENTART = 'KEIN_SCHEMA_FUER_DOKUMENTART';

    /** Der Provider unterstuetzt den Dateityp nicht. */
    case DATEITYP_NICHT_UNTERSTUETZT = 'DATEITYP_NICHT_UNTERSTUETZT';

    /** Die Antwort war nach allen Reparaturversuchen nicht schemakonform. */
    case SCHEMA_UNGUELTIG = 'SCHEMA_UNGUELTIG';

    /** Das Tagesbudget des Nutzers ist ausgeschoepft. */
    case TAGESLIMIT_ERREICHT = 'KI_TAGESLIMIT_ERREICHT';

    /** ProviderReleaseGate hat den Aufruf blockiert. */
    case PROVIDER_NICHT_FREIGEGEBEN = 'PROVIDER_NICHT_FREIGEGEBEN';

    /** Ratenbegrenzung des Providers. */
    case PROVIDER_RATE_LIMIT = 'PROVIDER_RATE_LIMIT';

    /** Technischer Fehler der Provideranbindung. */
    case PROVIDER_NICHT_ERREICHBAR = 'PROVIDER_NICHT_ERREICHBAR';

    /**
     * Eine fruehere Providerdatei ist noch nicht bestaetigt geloescht; es wird
     * keine weitere angelegt. Der Aufruf wartet auf die Wiederholung.
     */
    case PROVIDER_LOESCHUNG_OFFEN = 'PROVIDER_LOESCHUNG_OFFEN';

    /** Tagesbudget aktiv, aber fuer das Modell fehlt die Kalkulationsbasis. */
    case KALKULATIONSBASIS_FEHLT = 'KALKULATIONSBASIS_FEHLT';

    /** Unerwarteter Fehler der Adapterschicht. */
    case UNERWARTETER_FEHLER = 'UNERWARTETER_FEHLER';

    /**
     * Zugeordneter Uploadfehlercode oder null, wenn es keinen gibt.
     */
    public function uploadErrorCode(): ?UploadErrorCode
    {
        return match ($this) {
            self::QUELLE_NICHT_LESBAR => UploadErrorCode::QUELLE_NICHT_VORHANDEN,
            self::KEIN_SCHEMA_FUER_DOKUMENTART => UploadErrorCode::KLASSIFIKATION_FEHLGESCHLAGEN,
            self::DATEITYP_NICHT_UNTERSTUETZT => UploadErrorCode::ERWEITERUNG_UNZULAESSIG,
            self::SCHEMA_UNGUELTIG => UploadErrorCode::SCHEMA_UNGUELTIG,
            self::PROVIDER_NICHT_FREIGEGEBEN,
            self::PROVIDER_RATE_LIMIT,
            self::PROVIDER_NICHT_ERREICHBAR,
            self::KALKULATIONSBASIS_FEHLT => UploadErrorCode::KI_SCHICHT_NICHT_VERFUEGBAR,
            self::PROVIDER_LOESCHUNG_OFFEN => UploadErrorCode::PROVIDER_LOESCHUNG_OFFEN,
            self::UNERWARTETER_FEHLER => UploadErrorCode::EXTRAKTION_FEHLGESCHLAGEN,
            self::TAGESLIMIT_ERREICHT => null,
        };
    }

    /**
     * Code fuer ClassificationOutcome und ExtractionOutcome. Wo es einen
     * Uploadfehlercode gibt, wird dieser verwendet, damit die Pipeline die
     * passende deutsche Meldung am Dokument hinterlegt.
     */
    public function outcomeCode(): string
    {
        $uploadErrorCode = $this->uploadErrorCode();

        return $uploadErrorCode === null ? $this->value : $uploadErrorCode->value;
    }

    /**
     * Ist der Abbruch endgueltig? Endgueltige Fehler fuehren dazu, dass die
     * Pipeline die Quelldaten sofort loescht (Abschnitt 6.3 Schritt 16).
     * Voruebergehende Fehler werden mit Backoff wiederholt und spaetestens
     * nach dem letzten Versuch ebenfalls geloescht.
     */
    public function isPermanent(): bool
    {
        return match ($this) {
            self::PROVIDER_NICHT_FREIGEGEBEN,
            self::PROVIDER_RATE_LIMIT,
            self::PROVIDER_NICHT_ERREICHBAR,
            self::PROVIDER_LOESCHUNG_OFFEN,
            self::KALKULATIONSBASIS_FEHLT,
            self::UNERWARTETER_FEHLER => false,
            default => true,
        };
    }

    /**
     * Verstaendliche deutsche Meldung in Sie-Anrede, ohne Dateiinhalte.
     */
    public function message(): string
    {
        return match ($this) {
            self::QUELLE_NICHT_LESBAR => 'Die Quelldatei ist nicht mehr vorhanden. Bitte laden Sie die Unterlage erneut hoch.',
            self::KEIN_SCHEMA_FUER_DOKUMENTART => 'Für diese Unterlage ist keine automatische Auswertung hinterlegt. Bitte ordnen Sie die Unterlage manuell zu.',
            self::DATEITYP_NICHT_UNTERSTUETZT => 'Dieses Dateiformat kann nicht automatisch ausgewertet werden. Bitte laden Sie die Unterlage als PDF, JPG oder PNG hoch.',
            self::SCHEMA_UNGUELTIG => 'Die ausgelesenen Werte waren nicht vollständig prüfbar. Bitte erfassen Sie die Werte manuell.',
            self::TAGESLIMIT_ERREICHT => 'Ihr Tagesbudget für die automatische Auswertung ist ausgeschöpft. Die Unterlage wurde nicht ausgewertet und die Quelldatei wurde gelöscht. Bitte versuchen Sie es morgen erneut oder erfassen Sie die Werte manuell.',
            self::PROVIDER_NICHT_FREIGEGEBEN => 'Die automatische Auswertung ist gesperrt, weil die Datenschutzfreigabe für den Auswertungsdienst nicht vorliegt. Bitte erfassen Sie die Werte manuell.',
            self::PROVIDER_RATE_LIMIT => 'Die automatische Auswertung ist derzeit ausgelastet. Der Vorgang wird automatisch wiederholt.',
            self::PROVIDER_NICHT_ERREICHBAR => 'Die automatische Auswertung ist derzeit nicht verfügbar. Bitte versuchen Sie es später erneut.',
            self::PROVIDER_LOESCHUNG_OFFEN => 'Eine temporäre Auswertungsdatei aus einem früheren Schritt ist noch nicht bestätigt gelöscht. Die Auswertung wird automatisch wiederholt, sobald die Löschung bestätigt ist.',
            self::KALKULATIONSBASIS_FEHLT => 'Das Tagesbudget ist aktiv, für das konfigurierte Modell fehlt aber die Kalkulationsbasis. Der Aufruf wurde nicht ausgeführt. Der Betreiber muss die Kalkulationsbasis ergänzen oder das Tagesbudget bewusst abschalten.',
            self::UNERWARTETER_FEHLER => 'Es ist ein technischer Fehler aufgetreten. Bitte versuchen Sie es erneut.',
        };
    }
}
