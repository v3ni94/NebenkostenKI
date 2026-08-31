<?php

declare(strict_types=1);

namespace App\Services\Ai\Schemas;

/**
 * Schema fuer Rechnung, Gutschrift, Storno und Gebuehrenbescheid.
 *
 * Der ausgewiesene Lohnanteil nach Paragraf 35a EStG wird nur uebernommen,
 * wenn er im Dokument ausdruecklich beziffert ist. Materialkosten werden
 * niemals automatisch als beguenstigter Lohnanteil ausgegeben.
 */
final class InvoiceOrAssessmentSchema implements SchemaProviderInterface
{
    public static function key(): string
    {
        return 'rechnung_bescheid';
    }

    public static function version(): string
    {
        return '1.0.0';
    }

    public static function definition(): SchemaDefinition
    {
        $position = ObjectNode::make('Einzelposition des Belegs.')
            ->field('bezeichnung', FieldNode::text('Bezeichnung der Leistung, wie im Beleg ausgewiesen.', 200))
            ->field('betrag_brutto_cent', FieldNode::amountCent('Bruttobetrag der Position in Cent.', true))
            ->field('betrag_netto_cent', FieldNode::amountCent('Nettobetrag der Position in Cent.'))
            ->field('umsatzsteuer_cent', FieldNode::amountCent('Ausgewiesene Umsatzsteuer der Position in Cent.'))
            ->field('leistungszeitraum_von', FieldNode::isoDate('Beginn des Leistungszeitraums der Position.'))
            ->field('leistungszeitraum_bis', FieldNode::isoDate('Ende des Leistungszeitraums der Position.'))
            ->field('lohnanteil_cent', FieldNode::amountCent(
                'Ausdruecklich als Arbeits-, Maschinen- oder Fahrtkosten ausgewiesener Anteil in Cent. '
                .'Nur uebernehmen, wenn der Beleg diesen Anteil beziffert, sonst null.',
            ));

        $root = ObjectNode::make('Rechnung, Gutschrift, Storno oder Gebuehrenbescheid.')
            ->field('belegart', FieldNode::enumOf('Art des Belegs.', [
                'RECHNUNG',
                'GUTSCHRIFT',
                'STORNO',
                'GEBUEHRENBESCHEID',
            ]))
            ->field('aussteller', FieldNode::text('Rechnungssteller oder Behoerde.'))
            ->field('belegnummer', FieldNode::text('Rechnungs- oder Bescheidnummer.', 80))
            ->field('belegdatum', FieldNode::isoDate('Rechnungs- oder Bescheiddatum.'))
            ->field('leistungszeitraum_von', FieldNode::isoDate('Beginn des Leistungszeitraums des Belegs.'))
            ->field('leistungszeitraum_bis', FieldNode::isoDate('Ende des Leistungszeitraums des Belegs.'))
            ->field('objektanschrift', FieldNode::text('Objektanschrift, soweit im Beleg genannt.', 300))
            ->field('einheitsbezeichnung', FieldNode::text('Einheitsbezeichnung, soweit im Beleg genannt.'))
            ->field('gesamtbetrag_brutto_cent', FieldNode::amountCent('Gesamtbetrag brutto in Cent.', true))
            ->field('gesamtbetrag_netto_cent', FieldNode::amountCent('Gesamtbetrag netto in Cent.'))
            ->field('umsatzsteuer_gesamt_cent', FieldNode::amountCent('Ausgewiesene Umsatzsteuer gesamt in Cent.'))
            ->field('bezug_auf_belegnummer', FieldNode::text(
                'Bei Gutschrift oder Storno die Nummer des Ursprungsbelegs.',
                80,
            ))
            ->field('vorgeschlagene_kostenart', FieldNode::text(
                'Sachlich naheliegende Kostenart nach Abschnitt 12.1. Vorschlag, keine Freigabe.',
                120,
            ))
            ->listOf('positionen', $position, 'Einzelpositionen des Belegs.', 200);

        return new SchemaDefinition(self::key(), self::version(), $root);
    }
}
