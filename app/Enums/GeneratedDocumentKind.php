<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Art eines vom System erzeugten Artefakts.
 *
 * Nur erzeugte Artefakte duerfen dauerhaft gespeichert werden, niemals
 * hochgeladene Originalbelege.
 */
enum GeneratedDocumentKind: string
{
    case MIETERABRECHNUNG = 'MIETERABRECHNUNG';
    case EIGENTUEMERUEBERSICHT = 'EIGENTUEMERUEBERSICHT';
    case ANLAGE_35A = 'ANLAGE_35A';
    case ZIP_PAKET = 'ZIP_PAKET';
    case HVM_RECHNUNG = 'HVM_RECHNUNG';
    case DSGVO_EXPORT = 'DSGVO_EXPORT';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::MIETERABRECHNUNG => 'Mieterabrechnung',
            self::EIGENTUEMERUEBERSICHT => 'Eigentümerübersicht',
            self::ANLAGE_35A => 'Anlage nach Paragraf 35a EStG',
            self::ZIP_PAKET => 'ZIP-Paket',
            self::HVM_RECHNUNG => 'Rechnung der Hausverwaltung Müller GmbH',
            self::DSGVO_EXPORT => 'Datenexport nach DSGVO',
        };
    }
}
