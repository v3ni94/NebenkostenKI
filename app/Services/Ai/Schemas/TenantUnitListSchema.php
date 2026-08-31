<?php

declare(strict_types=1);

namespace App\Services\Ai\Schemas;

/**
 * Schema der Mieter- und Einheitenliste.
 *
 * Ueberschneidungen und Luecken der Belegung werden nicht bereinigt, sondern
 * uebernommen, wie sie in der Liste stehen. Die deterministische
 * Zeitachsenpruefung entscheidet ueber Konflikte (Abschnitt 9 Schritt 5).
 */
final class TenantUnitListSchema implements SchemaProviderInterface
{
    public static function key(): string
    {
        return 'mieter_einheitenliste';
    }

    public static function version(): string
    {
        return '1.0.0';
    }

    public static function definition(): SchemaDefinition
    {
        $tenancy = ObjectNode::make('Mietverhaeltnis einer Einheit.')
            ->field('mieter_name', FieldNode::text('Name des Mieters oder der Mieter.', 300))
            ->field('zustellanschrift', FieldNode::text('Zustellanschrift, soweit abweichend vom Objekt.', 300))
            ->field('mietbeginn', FieldNode::isoDate('Beginn des Mietverhaeltnisses.'))
            ->field('mietende', FieldNode::isoDate('Ende des Mietverhaeltnisses.'))
            ->field('personenanzahl', FieldNode::integer('Personenanzahl im Mietverhaeltnis.'))
            ->field('betriebskostenvorauszahlung_monatlich_cent', FieldNode::amountCent(
                'Monatliche Betriebskostenvorauszahlung in Cent.',
            ))
            ->field('heizkostenvorauszahlung_monatlich_cent', FieldNode::amountCent(
                'Monatliche Heizkostenvorauszahlung in Cent.',
            ));

        $unit = ObjectNode::make('Einheit des Objekts.')
            ->field('einheitsbezeichnung', FieldNode::text('Bezeichnung der Einheit.'))
            ->field('lage', FieldNode::text('Lage der Einheit, zum Beispiel Erdgeschoss links.', 120))
            ->field('wohnflaeche_qm', FieldNode::decimal('Wohnflaeche in Quadratmetern.'))
            ->field('beheizte_wohnflaeche_qm', FieldNode::decimal('Beheizte Wohnflaeche in Quadratmetern.'))
            ->field('miteigentumsanteil', FieldNode::decimal('Miteigentumsanteil der Einheit.'))
            ->field('leerstand', FieldNode::boolean('true, wenn die Einheit im Zeitraum leer stand.'))
            ->listOf('mietverhaeltnisse', $tenancy, 'Mietverhaeltnisse der Einheit im Zeitraum.', 20);

        $root = ObjectNode::make('Mieter- und Einheitenliste eines Objekts.')
            ->field('objektanschrift', FieldNode::text('Anschrift des Objekts.', 300))
            ->field('stichtag', FieldNode::isoDate('Stichtag der Liste, soweit ausgewiesen.'))
            ->field('zeitraum_von', FieldNode::isoDate('Beginn des in der Liste abgebildeten Zeitraums.'))
            ->field('zeitraum_bis', FieldNode::isoDate('Ende des in der Liste abgebildeten Zeitraums.'))
            ->field('gesamtflaeche_qm', FieldNode::decimal('Gesamtwohnflaeche des Objekts in Quadratmetern.'))
            ->field('anzahl_einheiten', FieldNode::integer('Anzahl der Einheiten in der Liste.'))
            ->listOf('einheiten', $unit, 'Einheiten des Objekts.', 400);

        return new SchemaDefinition(self::key(), self::version(), $root);
    }
}
