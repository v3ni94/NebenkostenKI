<?php

declare(strict_types=1);

namespace App\Services\Ai\Schemas;

/**
 * Fachliche Art eines extrahierten Wertes.
 *
 * Die Art steuert die serverseitige Validierung. Sie ist absichtlich NICHT
 * Teil des an den Provider gesendeten JSON Schemas, weil die strikten
 * Structured-Output-Modi der Provider nur einen begrenzten Satz an
 * JSON-Schema-Schluesselwoertern zulassen. Herstellerspezifische
 * Zusatzschluessel wuerden dort zu Ablehnungen fuehren.
 */
enum ValueKind: string
{
    /** Freitext, laengenbegrenzt. */
    case TEXT = 'TEXT';

    /**
     * Geldbetrag ausschliesslich als Integer in Cent (Grundsatz 8).
     * Ein Float wird als Schemaverletzung behandelt, nicht gerundet.
     */
    case AMOUNT_CENT = 'AMOUNT_CENT';

    /** Datum als ISO-8601-Kalenderdatum, also JJJJ-MM-TT. */
    case ISO_DATE = 'ISO_DATE';

    /**
     * Dezimalwert als Zeichenkette, zum Beispiel Flaeche, Miteigentumsanteil
     * oder Verbrauch. Niemals als binaerer Float (Grundsatz 8).
     */
    case DECIMAL_STRING = 'DECIMAL_STRING';

    /** Ganzzahl ohne Geldbezug, zum Beispiel Personenanzahl. */
    case INTEGER = 'INTEGER';

    /** Wahrheitswert. */
    case BOOLEAN = 'BOOLEAN';

    /** Wert aus einer geschlossenen Liste. */
    case ENUM = 'ENUM';
}
