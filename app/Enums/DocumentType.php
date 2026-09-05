<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Dokumentarten der Klassifikation nach Abschnitt 6.2 der Spezifikation.
 *
 * Der Wirtschaftsplan dient ausschliesslich dem Vergleich und ersetzt niemals
 * die tatsaechlichen Jahreskosten.
 */
enum DocumentType: string
{
    case WEG_HAUSGELDABRECHNUNG_GESAMT = 'WEG_HAUSGELDABRECHNUNG_GESAMT';
    case WEG_HAUSGELDABRECHNUNG_EINZEL = 'WEG_HAUSGELDABRECHNUNG_EINZEL';
    case WEG_WIRTSCHAFTSPLAN = 'WEG_WIRTSCHAFTSPLAN';
    case GRUNDSTEUERBESCHEID = 'GRUNDSTEUERBESCHEID';
    case WASSER_ABWASSERBESCHEID = 'WASSER_ABWASSERBESCHEID';
    case NIEDERSCHLAGSWASSERBESCHEID = 'NIEDERSCHLAGSWASSERBESCHEID';
    case STRASSENREINIGUNGSBESCHEID = 'STRASSENREINIGUNGSBESCHEID';
    case MUELLGEBUEHRENBESCHEID = 'MUELLGEBUEHRENBESCHEID';
    case VERSICHERUNGSRECHNUNG = 'VERSICHERUNGSRECHNUNG';
    case HAUSMEISTER_REINIGUNG_GARTEN = 'HAUSMEISTER_REINIGUNG_GARTEN';
    case ALLGEMEINSTROM = 'ALLGEMEINSTROM';
    case AUFZUG_WARTUNG_SCHORNSTEIN = 'AUFZUG_WARTUNG_SCHORNSTEIN';
    case HEIZKOSTENABRECHNUNG = 'HEIZKOSTENABRECHNUNG';
    case ENERGIE_BRENNSTOFFRECHNUNG = 'ENERGIE_BRENNSTOFFRECHNUNG';
    case MIETVERTRAG = 'MIETVERTRAG';
    case MIETVERTRAG_NACHTRAG = 'MIETVERTRAG_NACHTRAG';
    case VORJAHRESABRECHNUNG = 'VORJAHRESABRECHNUNG';
    case MIETER_EINHEITENLISTE = 'MIETER_EINHEITENLISTE';
    case ZAEHLERLISTE_ABLESEPROTOKOLL = 'ZAEHLERLISTE_ABLESEPROTOKOLL';
    case KONTOAUSZUG_ZAHLUNGSUEBERSICHT = 'KONTOAUSZUG_ZAHLUNGSUEBERSICHT';
    case RECHNUNG = 'RECHNUNG';
    case GUTSCHRIFT = 'GUTSCHRIFT';
    case STORNO = 'STORNO';
    case SONSTIGES = 'SONSTIGES';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::WEG_HAUSGELDABRECHNUNG_GESAMT => 'WEG-Hausgeldabrechnung Gesamtabrechnung',
            self::WEG_HAUSGELDABRECHNUNG_EINZEL => 'WEG-Hausgeldabrechnung Einzelabrechnung',
            self::WEG_WIRTSCHAFTSPLAN => 'Wirtschaftsplan der WEG',
            self::GRUNDSTEUERBESCHEID => 'Grundsteuerbescheid',
            self::WASSER_ABWASSERBESCHEID => 'Wasser- und Abwasserbescheid',
            self::NIEDERSCHLAGSWASSERBESCHEID => 'Niederschlagswasserbescheid',
            self::STRASSENREINIGUNGSBESCHEID => 'Straßenreinigungsbescheid',
            self::MUELLGEBUEHRENBESCHEID => 'Müllgebührenbescheid',
            self::VERSICHERUNGSRECHNUNG => 'Versicherungsrechnung',
            self::HAUSMEISTER_REINIGUNG_GARTEN => 'Hausmeister, Reinigung und Gartenpflege',
            self::ALLGEMEINSTROM => 'Allgemeinstrom',
            self::AUFZUG_WARTUNG_SCHORNSTEIN => 'Aufzug, Wartung und Schornsteinfeger',
            self::HEIZKOSTENABRECHNUNG => 'Heizkostenabrechnung',
            self::ENERGIE_BRENNSTOFFRECHNUNG => 'Energie- und Brennstoffrechnung',
            self::MIETVERTRAG => 'Mietvertrag',
            self::MIETVERTRAG_NACHTRAG => 'Nachtrag zum Mietvertrag',
            self::VORJAHRESABRECHNUNG => 'Vorjahres-Betriebskostenabrechnung',
            self::MIETER_EINHEITENLISTE => 'Mieter- und Einheitenliste',
            self::ZAEHLERLISTE_ABLESEPROTOKOLL => 'Zählerliste und Ableseprotokoll',
            self::KONTOAUSZUG_ZAHLUNGSUEBERSICHT => 'Kontoauszug oder Zahlungsübersicht',
            self::RECHNUNG => 'Rechnung',
            self::GUTSCHRIFT => 'Gutschrift',
            self::STORNO => 'Storno',
            self::SONSTIGES => 'Sonstiges Dokument',
        };
    }

    /**
     * Dokumentart darf niemals als Quelle tatsaechlicher Jahreskosten dienen.
     */
    public function isComparisonOnly(): bool
    {
        return match ($this) {
            self::WEG_WIRTSCHAFTSPLAN, self::VORJAHRESABRECHNUNG => true,
            default => false,
        };
    }
}
