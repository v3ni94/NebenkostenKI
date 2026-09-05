<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

/**
 * Codes der Schemaverletzungen.
 *
 * Die Codes bilden zusammen mit dem Schemapfad die vollstaendige
 * Fehlermeldung an das Modell und an das Protokoll. Der beanstandete Wert
 * wird nie mitgefuehrt, weil er Dokumentinhalt ist.
 */
enum SchemaViolationCode: string
{
    case UNGUELTIGES_JSON = 'UNGUELTIGES_JSON';
    case UNBEKANNTER_SCHLUESSEL = 'UNBEKANNTER_SCHLUESSEL';
    case PFLICHTFELD_FEHLT = 'PFLICHTFELD_FEHLT';
    case FALSCHER_TYP = 'FALSCHER_TYP';
    case NULL_NICHT_ZULAESSIG = 'NULL_NICHT_ZULAESSIG';
    case BETRAG_NICHT_INTEGER = 'BETRAG_NICHT_INTEGER';
    case UNGUELTIGES_DATUM = 'UNGUELTIGES_DATUM';
    case UNGUELTIGER_DEZIMALWERT = 'UNGUELTIGER_DEZIMALWERT';
    case KONFIDENZ_AUSSERHALB_BEREICH = 'KONFIDENZ_AUSSERHALB_BEREICH';
    case FUNDSTELLE_ZU_LANG = 'FUNDSTELLE_ZU_LANG';
    case TEXT_ZU_LANG = 'TEXT_ZU_LANG';
    case UNGUELTIGER_AUFZAEHLUNGSWERT = 'UNGUELTIGER_AUFZAEHLUNGSWERT';
    case SEITE_AUSSERHALB_BEREICH = 'SEITE_AUSSERHALB_BEREICH';
    case KEIN_OBJEKT = 'KEIN_OBJEKT';
    case KEINE_LISTE = 'KEINE_LISTE';
    case LISTE_ZU_LANG = 'LISTE_ZU_LANG';
    case UNGUELTIGE_BOUNDING_BOX = 'UNGUELTIGE_BOUNDING_BOX';

    /**
     * Praezise, sachliche Anweisung an das Modell fuer den
     * Reparaturversuch. Enthaelt keinen Dokumentinhalt.
     */
    public function repairInstruction(): string
    {
        return match ($this) {
            self::UNGUELTIGES_JSON => 'Die Antwort war kein gueltiges JSON-Objekt. Gib ausschliesslich ein JSON-Objekt ohne Rahmentext aus.',
            self::UNBEKANNTER_SCHLUESSEL => 'Der Schluessel ist im Schema nicht vorgesehen. Gib nur die im Schema definierten Schluessel aus.',
            self::PFLICHTFELD_FEHLT => 'Der Schluessel fehlt. Alle Schluessel des Schemas sind Pflichtschluessel. Fehlende Angaben werden mit value gleich null ausgegeben.',
            self::FALSCHER_TYP => 'Der Datentyp entspricht nicht dem Schema.',
            self::NULL_NICHT_ZULAESSIG => 'An dieser Stelle ist null nicht zulaessig.',
            self::BETRAG_NICHT_INTEGER => 'Geldbetraege werden ausschliesslich als Integer in Cent ausgegeben, also 1234 fuer 12,34 EUR. Kein Dezimalwert, keine Zeichenkette, kein Waehrungszeichen.',
            self::UNGUELTIGES_DATUM => 'Datumswerte werden im Format JJJJ-MM-TT ausgegeben und muessen ein gueltiges Kalenderdatum sein.',
            self::UNGUELTIGER_DEZIMALWERT => 'Dezimalwerte werden als Zeichenkette mit Punkt als Dezimaltrenner ausgegeben, zum Beispiel "72.50".',
            self::KONFIDENZ_AUSSERHALB_BEREICH => 'confidence ist eine Zahl zwischen 0 und 1.',
            self::FUNDSTELLE_ZU_LANG => 'source_excerpt ist auf eine kurze Fundstelle begrenzt. Gib nur den unmittelbar erforderlichen Ausschnitt aus.',
            self::TEXT_ZU_LANG => 'Der Text ueberschreitet die zulaessige Laenge des Feldes.',
            self::UNGUELTIGER_AUFZAEHLUNGSWERT => 'Der Wert ist in der Aufzaehlung des Schemas nicht enthalten. Verwende ausschliesslich die zugelassenen Werte oder null.',
            self::SEITE_AUSSERHALB_BEREICH => 'source_page ist eine Ganzzahl ab 1 oder null.',
            self::KEIN_OBJEKT => 'An dieser Stelle erwartet das Schema ein JSON-Objekt.',
            self::KEINE_LISTE => 'An dieser Stelle erwartet das Schema ein JSON-Array.',
            self::LISTE_ZU_LANG => 'Die Liste enthaelt mehr Eintraege als zulaessig.',
            self::UNGUELTIGE_BOUNDING_BOX => 'bounding_box enthaelt page, x, y, width und height als Zahlen oder ist null.',
        };
    }
}
