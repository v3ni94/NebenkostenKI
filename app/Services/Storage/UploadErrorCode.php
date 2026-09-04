<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Zentrale Fehlercodes des Uploadlebenszyklus nach Abschnitt 23.5.
 *
 * DATENSCHUTZ: Der Anzeigetext ist eine allgemeine Handlungsanweisung. Er
 * enthaelt niemals Dateiinhalte, Dateinamen, Auszuege oder Rohantworten. Der
 * Code wird in documents.failure_code, processing_jobs.error_code und
 * source_deletion_events.error_code gespeichert und ist damit
 * maschinenauswertbar, ohne personenbezogene Daten zu fuehren.
 */
enum UploadErrorCode: string
{
    case MIME_UNBEKANNT = 'MIME_UNBEKANNT';
    case MIME_TAEUSCHUNG = 'MIME_TAEUSCHUNG';
    case ERWEITERUNG_UNZULAESSIG = 'ERWEITERUNG_UNZULAESSIG';
    case AUSFUEHRBARER_INHALT = 'AUSFUEHRBARER_INHALT';
    case DATEI_ZU_GROSS = 'DATEI_ZU_GROSS';
    case DATEI_LEER = 'DATEI_LEER';
    case LAUF_LIMIT_ERREICHT = 'LAUF_LIMIT_ERREICHT';
    case STRUKTUR_UNGUELTIG = 'STRUKTUR_UNGUELTIG';
    case ARCHIV_TRAVERSAL = 'ARCHIV_TRAVERSAL';
    case ARCHIV_ZIP_BOMBE = 'ARCHIV_ZIP_BOMBE';
    case ARCHIV_EINTRAG_UNZULAESSIG = 'ARCHIV_EINTRAG_UNZULAESSIG';
    case ARCHIV_VERSCHACHTELT = 'ARCHIV_VERSCHACHTELT';
    case ARCHIV_LEER = 'ARCHIV_LEER';
    case MALWARE_GEFUNDEN = 'MALWARE_GEFUNDEN';
    case MALWARE_PRUEFUNG_FEHLGESCHLAGEN = 'MALWARE_PRUEFUNG_FEHLGESCHLAGEN';
    case HEIC_KONVERTER_FEHLT = 'HEIC_KONVERTER_FEHLT';
    case CHUNK_FEHLT = 'CHUNK_FEHLT';
    case CHUNK_INDEX_UNGUELTIG = 'CHUNK_INDEX_UNGUELTIG';
    case CHUNK_ANZAHL_UNGUELTIG = 'CHUNK_ANZAHL_UNGUELTIG';
    case UPLOAD_ABGELAUFEN = 'UPLOAD_ABGELAUFEN';
    case UPLOAD_BEREITS_ABGESCHLOSSEN = 'UPLOAD_BEREITS_ABGESCHLOSSEN';
    case DUBLETTE = 'DUBLETTE';
    case ARTEFAKT_UNZULAESSIG = 'ARTEFAKT_UNZULAESSIG';
    case ARTEFAKT_DISK_UNZULAESSIG = 'ARTEFAKT_DISK_UNZULAESSIG';
    case QUELLE_NICHT_VORHANDEN = 'QUELLE_NICHT_VORHANDEN';
    case QUELLE_NICHT_LESBAR = 'QUELLE_NICHT_LESBAR';
    case LOESCHUNG_FEHLGESCHLAGEN = 'LOESCHUNG_FEHLGESCHLAGEN';
    case PROVIDER_LOESCHUNG_FEHLGESCHLAGEN = 'PROVIDER_LOESCHUNG_FEHLGESCHLAGEN';
    case PROVIDER_LOESCHUNG_OFFEN = 'PROVIDER_LOESCHUNG_OFFEN';
    case KURZZEITBEREICH_SCHREIBFEHLER = 'KURZZEITBEREICH_SCHREIBFEHLER';
    case TTL_ABGELAUFEN = 'TTL_ABGELAUFEN';
    case KI_SCHICHT_NICHT_VERFUEGBAR = 'KI_SCHICHT_NICHT_VERFUEGBAR';
    case KLASSIFIKATION_FEHLGESCHLAGEN = 'KLASSIFIKATION_FEHLGESCHLAGEN';
    case EXTRAKTION_FEHLGESCHLAGEN = 'EXTRAKTION_FEHLGESCHLAGEN';
    case SCHEMA_UNGUELTIG = 'SCHEMA_UNGUELTIG';
    case KI_TAGESLIMIT_ERREICHT = 'KI_TAGESLIMIT_ERREICHT';
    case LEASE_ABGELAUFEN = 'LEASE_ABGELAUFEN';
    case UNERWARTETER_FEHLER = 'UNERWARTETER_FEHLER';

    /**
     * Verstaendlicher deutscher Hinweis fuer die Oberflaeche, in Sie-Anrede.
     */
    public function message(): string
    {
        return match ($this) {
            self::MIME_UNBEKANNT => 'Das Dateiformat konnte nicht erkannt werden. Bitte laden Sie die Unterlage als PDF, JPG, PNG oder CSV hoch.',
            self::MIME_TAEUSCHUNG => 'Der Inhalt der Datei passt nicht zur Dateiendung. Bitte laden Sie die Originaldatei unverändert erneut hoch.',
            self::ERWEITERUNG_UNZULAESSIG => 'Dieses Dateiformat wird nicht unterstützt. Zulässig sind PDF, JPG, PNG, HEIC, CSV und ZIP. Excel-Tabellen speichern Sie bitte als CSV oder PDF.',
            self::AUSFUEHRBARER_INHALT => 'Die Datei enthält ausführbaren Programmcode und wurde deshalb abgelehnt.',
            self::DATEI_ZU_GROSS => 'Die Datei überschreitet die zulässige Größe je Datei. Bitte teilen Sie die Unterlage oder verringern Sie die Auflösung des Scans.',
            self::DATEI_LEER => 'Die Datei ist leer. Bitte prüfen Sie die Unterlage und laden Sie sie erneut hoch.',
            self::LAUF_LIMIT_ERREICHT => 'Das Gesamtvolumen für diesen Abrechnungslauf ist erreicht. Bitte entfernen Sie nicht benötigte Unterlagen oder verringern Sie die Dateigrößen.',
            self::STRUKTUR_UNGUELTIG => 'Die Datei ist beschädigt oder unvollständig. Bitte erzeugen Sie die Datei erneut und laden Sie sie hoch.',
            self::ARCHIV_TRAVERSAL => 'Das Archiv enthält einen unzulässigen Pfad und wurde vollständig abgelehnt.',
            self::ARCHIV_ZIP_BOMBE => 'Das Archiv entpackt sich unverhältnismäßig groß und wurde abgelehnt. Bitte laden Sie die enthaltenen Unterlagen einzeln hoch.',
            self::ARCHIV_EINTRAG_UNZULAESSIG => 'Das Archiv enthält eine Datei in einem nicht unterstützten Format. Bitte laden Sie die benötigten Unterlagen einzeln hoch.',
            self::ARCHIV_VERSCHACHTELT => 'Das Archiv enthält ein weiteres Archiv. Bitte laden Sie die Unterlagen einzeln hoch.',
            self::ARCHIV_LEER => 'Das Archiv enthält keine auswertbare Unterlage.',
            self::MALWARE_GEFUNDEN => 'Die Datei wurde von der Sicherheitsprüfung abgelehnt. Bitte prüfen Sie die Datei auf Ihrem Gerät.',
            self::MALWARE_PRUEFUNG_FEHLGESCHLAGEN => 'Die Sicherheitsprüfung war technisch nicht möglich. Bitte versuchen Sie es später erneut.',
            self::HEIC_KONVERTER_FEHLT => 'HEIC-Bilder können auf diesem Server nicht umgewandelt werden. Bitte speichern Sie das Foto auf Ihrem Gerät als JPG oder PDF und laden Sie es erneut hoch. Auf dem iPhone: Einstellungen, Kamera, Formate, "Maximale Kompatibilität".',
            self::CHUNK_FEHLT => 'Der Upload ist unvollständig. Bitte setzen Sie den Upload fort oder laden Sie die Datei erneut hoch.',
            self::CHUNK_INDEX_UNGUELTIG => 'Der übertragene Dateiabschnitt ist ungültig. Bitte laden Sie die Datei erneut hoch.',
            self::CHUNK_ANZAHL_UNGUELTIG => 'Die Angaben zum Upload sind nicht plausibel. Bitte laden Sie die Datei erneut hoch.',
            self::UPLOAD_ABGELAUFEN => 'Die Verarbeitungsfrist für diese Datei ist abgelaufen und die Datei wurde gelöscht. Bitte laden Sie sie erneut hoch.',
            self::UPLOAD_BEREITS_ABGESCHLOSSEN => 'Dieser Upload ist bereits abgeschlossen.',
            self::KI_TAGESLIMIT_ERREICHT => 'Das Tageslimit für die automatische Auswertung ist erreicht. Ihre Unterlagen bleiben erhalten. Bitte setzen Sie die Auswertung morgen fort oder erfassen Sie die Angaben vorerst von Hand.',
            self::DUBLETTE => 'Diese Unterlage wurde in diesem Abrechnungslauf bereits hochgeladen.',
            self::ARTEFAKT_UNZULAESSIG => 'Es wurde versucht, eine nicht freigegebene Datei im dauerhaften Speicher abzulegen. Der Vorgang wurde abgebrochen.',
            self::ARTEFAKT_DISK_UNZULAESSIG => 'Der Zielspeicher ist für Ergebnisartefakte nicht zugelassen.',
            self::QUELLE_NICHT_VORHANDEN => 'Die Quelldatei ist nicht mehr vorhanden. Bitte laden Sie die Unterlage erneut hoch.',
            self::QUELLE_NICHT_LESBAR => 'Die Datei konnte im Kurzzeitbereich nicht mehr gelesen werden und wurde gelöscht. Bitte laden Sie die Unterlage erneut hoch.',
            self::LOESCHUNG_FEHLGESCHLAGEN => 'Die Löschung der Quelldatei ist fehlgeschlagen. Der Vorgang wird automatisch wiederholt.',
            self::PROVIDER_LOESCHUNG_FEHLGESCHLAGEN => 'Die Löschung der temporären Auswertungsdatei ist fehlgeschlagen. Der Vorgang wird automatisch wiederholt.',
            self::PROVIDER_LOESCHUNG_OFFEN => 'Eine temporäre Auswertungsdatei aus einem früheren Schritt ist noch nicht bestätigt gelöscht. Die Auswertung wird automatisch wiederholt, sobald die Löschung bestätigt ist.',
            self::KURZZEITBEREICH_SCHREIBFEHLER => 'Die Datei konnte im Kurzzeitbereich nicht gespeichert werden. Bitte versuchen Sie es erneut.',
            self::TTL_ABGELAUFEN => 'Die Auswertung wurde nicht rechtzeitig abgeschlossen. Die Datei wurde gelöscht. Bitte laden Sie sie erneut hoch.',
            self::KI_SCHICHT_NICHT_VERFUEGBAR => 'Die automatische Auswertung ist derzeit nicht verfügbar. Bitte versuchen Sie es später erneut.',
            self::KLASSIFIKATION_FEHLGESCHLAGEN => 'Die Art der Unterlage konnte nicht bestimmt werden. Bitte ordnen Sie die Unterlage manuell zu.',
            self::EXTRAKTION_FEHLGESCHLAGEN => 'Aus der Unterlage konnten keine verwertbaren Werte ausgelesen werden. Bitte erfassen Sie die Werte manuell.',
            self::SCHEMA_UNGUELTIG => 'Die ausgelesenen Werte waren nicht vollständig prüfbar. Bitte erfassen Sie die Werte manuell.',
            self::LEASE_ABGELAUFEN => 'Die Verarbeitung wurde unterbrochen und automatisch neu eingeplant.',
            self::UNERWARTETER_FEHLER => 'Es ist ein technischer Fehler aufgetreten. Bitte versuchen Sie es erneut.',
        };
    }

    /**
     * Endgueltige Fehler werden nicht wiederholt. Die Quelldaten werden sofort
     * geloescht, ein neuer Versuch erfordert einen neuen Upload
     * (Abschnitt 6.3 Schritt 16).
     */
    public function isPermanent(): bool
    {
        return match ($this) {
            self::MALWARE_PRUEFUNG_FEHLGESCHLAGEN,
            self::LOESCHUNG_FEHLGESCHLAGEN,
            self::PROVIDER_LOESCHUNG_FEHLGESCHLAGEN,
            self::PROVIDER_LOESCHUNG_OFFEN,
            self::KURZZEITBEREICH_SCHREIBFEHLER,
            self::KI_SCHICHT_NICHT_VERFUEGBAR,
            self::LEASE_ABGELAUFEN,
            self::UNERWARTETER_FEHLER => false,
            default => true,
        };
    }
}
