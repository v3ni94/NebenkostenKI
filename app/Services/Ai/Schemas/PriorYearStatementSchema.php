<?php

declare(strict_types=1);

namespace App\Services\Ai\Schemas;

/**
 * Schema der Vorjahres-Betriebskostenabrechnung.
 *
 * Vorjahreswerte dienen ausschliesslich dem Vergleich und werden niemals als
 * neue Kosten uebernommen (Abschnitt 8.3). Uebernommene Felder tragen in der
 * Oberflaeche sichtbar den Hinweis "Aus Vorjahr uebernommen".
 */
final class PriorYearStatementSchema implements SchemaProviderInterface
{
    public static function key(): string
    {
        return 'vorjahresabrechnung';
    }

    public static function version(): string
    {
        return '1.0.0';
    }

    public static function definition(): SchemaDefinition
    {
        $line = ObjectNode::make('Kostenzeile der Vorjahresabrechnung.')
            ->field('bezeichnung', FieldNode::text('Bezeichnung der Kostenart.', 200))
            ->field('gesamtkosten_cent', FieldNode::amountCent('Gesamtkosten der Kostenart im Vorjahr in Cent.'))
            ->field('mieteranteil_cent', FieldNode::amountCent('Auf den Mieter entfallender Anteil im Vorjahr in Cent.'))
            ->field('verteilerschluessel', FieldNode::enumOf(
                'Im Vorjahr angewandter Verteilerschluessel.',
                LeaseContractSchema::allocationKeyValues(),
            ))
            ->field('nenner', FieldNode::decimal('Gesamtnenner des Schluessels, soweit ausgewiesen.'))
            ->field('zaehler', FieldNode::decimal('Individueller Zaehler des Schluessels, soweit ausgewiesen.'));

        $root = ObjectNode::make('Betriebskostenabrechnung eines Vorjahres, ausschliesslich als Vergleich.')
            ->field('abrechnungszeitraum_von', FieldNode::isoDate('Beginn des Abrechnungszeitraums.'))
            ->field('abrechnungszeitraum_bis', FieldNode::isoDate('Ende des Abrechnungszeitraums.'))
            ->field('objektanschrift', FieldNode::text('Anschrift des Objekts.', 300))
            ->field('einheitsbezeichnung', FieldNode::text('Bezeichnung der Einheit.'))
            ->field('mieter_name', FieldNode::text('Name des Mieters.', 300))
            ->field('nutzungszeitraum_von', FieldNode::isoDate('Beginn des Nutzungszeitraums des Mieters.'))
            ->field('nutzungszeitraum_bis', FieldNode::isoDate('Ende des Nutzungszeitraums des Mieters.'))
            ->field('summe_umlagefaehige_kosten_cent', FieldNode::amountCent('Summe der umgelegten Kosten in Cent.'))
            ->field('vorauszahlungen_cent', FieldNode::amountCent('Abgezogene Vorauszahlungen in Cent.'))
            ->field('ergebnis_cent', FieldNode::amountCent(
                'Ergebnis in Cent. Positiv bedeutet Nachzahlung, negativ Guthaben.',
            ))
            ->field('heizkosten_extern_abgerechnet', FieldNode::boolean(
                'true, wenn die Heizkosten im Vorjahr extern abgerechnet wurden.',
            ))
            ->listOf('kostenzeilen', $line, 'Kostenzeilen der Vorjahresabrechnung.', 120);

        return new SchemaDefinition(self::key(), self::version(), $root);
    }
}
