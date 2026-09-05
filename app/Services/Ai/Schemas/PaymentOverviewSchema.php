<?php

declare(strict_types=1);

namespace App\Services\Ai\Schemas;

/**
 * Schema der Zahlungsuebersicht beziehungsweise des Kontoauszugs fuer
 * geleistete Vorauszahlungen.
 *
 * Datenminimierung: IBAN, BIC und Kontonummern werden bewusst NICHT
 * extrahiert. Fuer die Abrechnung genuegen Datum, Betrag, Verwendungszweck
 * in Kurzform und die Zuordnung zu Einheit oder Mietverhaeltnis. Ein
 * Zahlungseingang wird nur zugeordnet, wenn die Zuordnung im Dokument
 * erkennbar ist, sonst bleibt sie null.
 */
final class PaymentOverviewSchema implements SchemaProviderInterface
{
    public static function key(): string
    {
        return 'zahlungsuebersicht';
    }

    public static function version(): string
    {
        return '1.0.0';
    }

    public static function definition(): SchemaDefinition
    {
        $entry = ObjectNode::make('Einzelner Zahlungseingang.')
            ->field('buchungsdatum', FieldNode::isoDate('Buchungsdatum des Zahlungseingangs.'))
            ->field('wertstellungsdatum', FieldNode::isoDate('Wertstellungsdatum, soweit ausgewiesen.'))
            ->field('betrag_cent', FieldNode::amountCent('Betrag des Zahlungseingangs in Cent.', true))
            ->field('zahlungsart', FieldNode::enumOf('Art des Eingangs.', [
                'EINGANG',
                'RUECKBUCHUNG',
                'AUSGANG',
                'UNBEKANNT',
            ]))
            ->field('einzahler_bezeichnung', FieldNode::text(
                'Kurzbezeichnung des Einzahlers, wie im Auszug ausgewiesen. Keine Bankverbindung.',
                120,
            ))
            ->field('verwendungszweck_kurz', FieldNode::text(
                'Verwendungszweck in Kurzform, hoechstens 120 Zeichen.',
                120,
            ))
            ->field('zugeordnete_einheit', FieldNode::text('Einheit, soweit im Verwendungszweck erkennbar.'))
            ->field('zugeordneter_mieter', FieldNode::text('Mieter, soweit im Verwendungszweck erkennbar.', 300))
            ->field('betrifft_betriebskosten', FieldNode::boolean(
                'true, wenn der Eingang erkennbar eine Betriebskostenvorauszahlung betrifft. '
                .'Bei unklarem Verwendungszweck null.',
            ));

        $root = ObjectNode::make('Zahlungsuebersicht oder Kontoauszug fuer Vorauszahlungen.')
            ->field('zeitraum_von', FieldNode::isoDate('Beginn des abgebildeten Zeitraums.'))
            ->field('zeitraum_bis', FieldNode::isoDate('Ende des abgebildeten Zeitraums.'))
            ->field('objektanschrift', FieldNode::text('Objektanschrift, soweit erkennbar.', 300))
            ->field('summe_eingaenge_cent', FieldNode::amountCent('Summe der Zahlungseingaenge in Cent.'))
            ->field('anzahl_buchungen', FieldNode::integer('Anzahl der uebernommenen Buchungen.'))
            ->listOf('buchungen', $entry, 'Einzelne Buchungen.', 400);

        return new SchemaDefinition(self::key(), self::version(), $root);
    }
}
