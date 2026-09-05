<?php

declare(strict_types=1);

namespace App\Services\Ai\Schemas;

/**
 * Schema der Zaehlerwerte, Zaehlerliste und Ableseprotokolle.
 *
 * Fehlt eine Zwischenablesung bei Nutzerwechsel, wird der Verbrauch nicht
 * geschaetzt (Abschnitt 11.2). Das Feld zwischenablesung kennzeichnet den
 * Ablesegrund, damit die deterministische Berechnung entscheiden kann.
 */
final class MeterReadingsSchema implements SchemaProviderInterface
{
    public static function key(): string
    {
        return 'zaehlerwerte';
    }

    public static function version(): string
    {
        return '1.0.0';
    }

    public static function definition(): SchemaDefinition
    {
        $reading = ObjectNode::make('Einzelner Zaehlerwert.')
            ->field('zaehlernummer', FieldNode::text('Zaehlernummer.', 80))
            ->field('zaehlertyp', FieldNode::enumOf('Art des Zaehlers.', [
                'KALTWASSER',
                'WARMWASSER',
                'WAERMEMENGE',
                'HEIZKOSTENVERTEILER',
                'STROM',
                'GAS',
                'SONSTIGES',
            ]))
            ->field('einheitsbezeichnung', FieldNode::text('Einheit, der der Zaehler zugeordnet ist.'))
            ->field('raum', FieldNode::text('Raum oder Aufstellort, soweit ausgewiesen.', 120))
            ->field('ablesedatum', FieldNode::isoDate('Datum der Ablesung.'))
            ->field('ablesewert', FieldNode::decimal('Abgelesener Zaehlerstand als Dezimalzeichenkette.'))
            ->field('masseinheit', FieldNode::text('Masseinheit des Zaehlers, zum Beispiel m3 oder kWh.', 20))
            ->field('ablesegrund', FieldNode::enumOf('Grund der Ablesung.', [
                'JAHRESABLESUNG',
                'ZWISCHENABLESUNG_NUTZERWECHSEL',
                'ZWISCHENABLESUNG_SONSTIGE',
                'GERAETEWECHSEL',
                'UNBEKANNT',
            ]))
            ->field('geschaetzt', FieldNode::boolean(
                'true, wenn das Dokument den Wert ausdruecklich als geschaetzt kennzeichnet.',
            ));

        $root = ObjectNode::make('Zaehlerliste oder Ableseprotokoll.')
            ->field('objektanschrift', FieldNode::text('Anschrift des Objekts.', 300))
            ->field('ableser', FieldNode::text('Ablesender Dienstleister, soweit ausgewiesen.'))
            ->field('zeitraum_von', FieldNode::isoDate('Beginn des Ablesezeitraums.'))
            ->field('zeitraum_bis', FieldNode::isoDate('Ende des Ablesezeitraums.'))
            ->field('anzahl_zaehler', FieldNode::integer('Anzahl der erfassten Zaehler.'))
            ->listOf('zaehlerwerte', $reading, 'Einzelne Zaehlerwerte.', 400);

        return new SchemaDefinition(self::key(), self::version(), $root);
    }
}
