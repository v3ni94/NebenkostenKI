<?php

declare(strict_types=1);

namespace App\Services\Ai\Schemas;

/**
 * Schema des Mietvertrags und seiner Nachtraege.
 *
 * Der Mietvertrag ist die vorrangige Quelle fuer Umlagevereinbarung und
 * Verteilerschluessel (Abschnitt 8 Schritt 8). Ein WEG-Schluessel wird
 * niemals stillschweigend mit dem mietvertraglichen Umlageschluessel
 * gleichgesetzt. Der Vertragstext wird nicht dauerhaft gespeichert, nur die
 * strukturierten Felder mit kurzem Fundstellenausschnitt.
 */
final class LeaseContractSchema implements SchemaProviderInterface
{
    public static function key(): string
    {
        return 'mietvertrag';
    }

    public static function version(): string
    {
        return '1.0.0';
    }

    public static function definition(): SchemaDefinition
    {
        $agreedCost = ObjectNode::make('Ausdruecklich vereinbarte Betriebskostenart.')
            ->field('bezeichnung', FieldNode::text('Bezeichnung der Kostenart im Vertrag.', 200))
            ->field('umlage_vereinbart', FieldNode::boolean('true, wenn die Umlage dieser Kostenart ausdruecklich vereinbart ist.'))
            ->field('verteilerschluessel', FieldNode::enumOf('Vereinbarter Verteilerschluessel.', self::allocationKeyValues()));

        $root = ObjectNode::make('Mietvertrag oder Nachtrag zum Mietvertrag.')
            ->field('vertragsart', FieldNode::enumOf('Art des Dokuments.', ['MIETVERTRAG', 'NACHTRAG']))
            ->field('nutzungsart', FieldNode::enumOf(
                'Nutzungsart des Mietverhaeltnisses. Gewerbe wird nicht nach Wohnraummietrecht abgerechnet.',
                ['WOHNRAUM', 'GEWERBE', 'GEMISCHT', 'UNBEKANNT'],
            ))
            ->field('vermieter_name', FieldNode::text('Name des Vermieters.'))
            ->field('mieter_name', FieldNode::text('Name des Mieters oder der Mieter.', 300))
            ->field('objektanschrift', FieldNode::text('Anschrift des Objekts.', 300))
            ->field('einheitsbezeichnung', FieldNode::text('Bezeichnung oder Lage der Einheit.'))
            ->field('wohnflaeche_qm', FieldNode::decimal('Vertraglich vereinbarte Wohnflaeche in Quadratmetern.'))
            ->field('mietbeginn', FieldNode::isoDate('Beginn des Mietverhaeltnisses.'))
            ->field('mietende', FieldNode::isoDate('Ende des Mietverhaeltnisses, soweit vereinbart.'))
            ->field('personenanzahl', FieldNode::integer('Vertraglich genannte Personenanzahl.'))
            ->field('nettokaltmiete_cent', FieldNode::amountCent('Monatliche Nettokaltmiete in Cent.'))
            ->field('betriebskostenvorauszahlung_monatlich_cent', FieldNode::amountCent(
                'Monatliche Betriebskostenvorauszahlung in Cent, ohne Heizkosten.',
            ))
            ->field('heizkostenvorauszahlung_monatlich_cent', FieldNode::amountCent(
                'Getrennt vereinbarte monatliche Heizkostenvorauszahlung in Cent.',
            ))
            ->field('vorauszahlung_art', FieldNode::enumOf(
                'Art der Vereinbarung zu den Betriebskosten.',
                ['VORAUSZAHLUNG', 'PAUSCHALE', 'KEINE_VEREINBARUNG', 'UNKLAR'],
            ))
            ->field('umlage_sonstige_betriebskosten_vereinbart', FieldNode::boolean(
                'true, wenn "sonstige Betriebskosten" ausdruecklich und konkret vereinbart sind.',
            ))
            ->field('standardverteilerschluessel', FieldNode::enumOf(
                'Allgemein vereinbarter Verteilerschluessel, soweit einheitlich geregelt.',
                self::allocationKeyValues(),
            ))
            ->field('heizkostenabrechnung_extern', FieldNode::boolean(
                'true, wenn der Vertrag eine externe Heizkostenabrechnung vorsieht.',
            ))
            ->field('dezentrale_energieversorgung', FieldNode::boolean(
                'true, wenn der Mieter Energie direkt bezieht. Dann duerfen keine Heizkosten als '
                .'Vermieterkosten angesetzt werden.',
            ))
            ->field('abweichende_regelung_hinweis', FieldNode::text(
                'Kurzer sachlicher Hinweis auf eine ungewoehnliche oder unklare Umlageregelung.',
                240,
            ))
            ->listOf('vereinbarte_kostenarten', $agreedCost, 'Ausdruecklich vereinbarte Kostenarten.', 60);

        return new SchemaDefinition(self::key(), self::version(), $root);
    }

    /**
     * Verteilerschluessel nach Abschnitt 9 Schritt 8.
     *
     * @return list<string>
     */
    public static function allocationKeyValues(): array
    {
        return [
            'WOHNFLAECHE',
            'BEHEIZTE_WOHNFLAECHE',
            'MITEIGENTUMSANTEILE',
            'PERSONEN',
            'PERSONENTAGE',
            'EINHEITEN',
            'VERBRAUCH',
            'DIREKTE_ZUORDNUNG',
            'INDIVIDUELL_1',
            'INDIVIDUELL_2',
            'INDIVIDUELL_3',
            'INDIVIDUELL_4',
            'INDIVIDUELL_5',
            'NICHT_GEREGELT',
        ];
    }
}
