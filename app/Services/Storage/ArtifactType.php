<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Enums\GeneratedDocumentKind;

/**
 * Abschliessende Liste der Dateien, die dauerhaft gespeichert werden duerfen.
 *
 * VERBINDLICH (Abschnitt 3.4, ADR-007): Nur vom System erzeugte Artefakte
 * gelangen auf die Disks "sftp" oder "s3". Es gibt bewusst KEINEN Artefakttyp
 * fuer einen Originalupload, fuer ein Seitenbild oder fuer einen OCR-Text.
 * Weil ArtifactStorage ausschliesslich mit diesem Enum aufgerufen werden kann,
 * ist eine dauerhafte Ablage einer Quelldatei technisch ausgeschlossen und
 * nicht nur organisatorisch untersagt.
 */
enum ArtifactType: string
{
    case MIETERABRECHNUNG_VORSCHAU = 'MIETERABRECHNUNG_VORSCHAU';
    case MIETERABRECHNUNG_FINAL = 'MIETERABRECHNUNG_FINAL';
    case EIGENTUEMERUEBERSICHT = 'EIGENTUEMERUEBERSICHT';
    case ANLAGE_35A = 'ANLAGE_35A';
    case ZIP_PAKET = 'ZIP_PAKET';
    case HVM_RECHNUNG = 'HVM_RECHNUNG';
    case DSGVO_EXPORT = 'DSGVO_EXPORT';

    public function label(): string
    {
        return match ($this) {
            self::MIETERABRECHNUNG_VORSCHAU => 'Mieterabrechnung, Vorschau mit Wasserzeichen',
            self::MIETERABRECHNUNG_FINAL => 'Mieterabrechnung, Finalversion',
            self::EIGENTUEMERUEBERSICHT => 'Eigentümerübersicht',
            self::ANLAGE_35A => 'Anlage nach Paragraf 35a EStG',
            self::ZIP_PAKET => 'ZIP-Paket',
            self::HVM_RECHNUNG => 'Rechnung der Hausverwaltung Müller GmbH',
            self::DSGVO_EXPORT => 'Datenexport nach DSGVO',
        };
    }

    /**
     * Unterverzeichnis in der Artefaktablage.
     */
    public function directory(): string
    {
        return match ($this) {
            self::MIETERABRECHNUNG_VORSCHAU => 'abrechnungen/vorschau',
            self::MIETERABRECHNUNG_FINAL => 'abrechnungen/final',
            self::EIGENTUEMERUEBERSICHT => 'eigentuemeruebersichten',
            self::ANLAGE_35A => 'anlagen-35a',
            self::ZIP_PAKET => 'pakete',
            self::HVM_RECHNUNG => 'rechnungen',
            self::DSGVO_EXPORT => 'datenexporte',
        };
    }

    public function extension(): string
    {
        return match ($this) {
            self::ZIP_PAKET, self::DSGVO_EXPORT => 'zip',
            default => 'pdf',
        };
    }

    /**
     * Erwartete Magic Bytes. Ein Inhalt ohne diese Signatur wird abgewiesen.
     * Damit kann selbst ein Programmierfehler kein JPEG, HEIC, XLSX oder
     * beliebiges Original in die dauerhafte Ablage schreiben.
     */
    public function magicPrefix(): string
    {
        return match ($this->extension()) {
            'zip' => "PK\x03\x04",
            default => '%PDF-',
        };
    }

    public function mimeType(): string
    {
        return match ($this->extension()) {
            'zip' => 'application/zip',
            default => 'application/pdf',
        };
    }

    /**
     * Zuordnung zum persistierten Enum der Tabelle generated_documents.
     */
    public function kind(): GeneratedDocumentKind
    {
        return match ($this) {
            self::MIETERABRECHNUNG_VORSCHAU, self::MIETERABRECHNUNG_FINAL => GeneratedDocumentKind::MIETERABRECHNUNG,
            self::EIGENTUEMERUEBERSICHT => GeneratedDocumentKind::EIGENTUEMERUEBERSICHT,
            self::ANLAGE_35A => GeneratedDocumentKind::ANLAGE_35A,
            self::ZIP_PAKET => GeneratedDocumentKind::ZIP_PAKET,
            self::HVM_RECHNUNG => GeneratedDocumentKind::HVM_RECHNUNG,
            self::DSGVO_EXPORT => GeneratedDocumentKind::DSGVO_EXPORT,
        };
    }
}
