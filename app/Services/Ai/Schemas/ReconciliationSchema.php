<?php

declare(strict_types=1);

namespace App\Services\Ai\Schemas;

/**
 * Schema des Abgleichs mehrerer Dokumente nach Abschnitt 7.4.
 *
 * Das Modell liefert ausschliesslich Hinweise und Vergleichszeilen. Es
 * berechnet keine Betraege und faellt keine Entscheidung. Ob eine Dublette
 * vorliegt und ob eine Abweichung die Finalisierung blockiert, entscheidet
 * die deterministische Regel-Engine.
 */
final class ReconciliationSchema implements SchemaProviderInterface
{
    public static function key(): string
    {
        return 'reconciliation';
    }

    public static function version(): string
    {
        return '1.0.0';
    }

    public static function definition(): SchemaDefinition
    {
        $matrixRow = ObjectNode::make('Zeile der Reconciliation-Matrix.')
            ->field('quelle', FieldNode::enumOf('Quelle der Kostenangabe.', [
                'HAUSGELDABRECHNUNG',
                'EXTERNE_HEIZKOSTENABRECHNUNG',
                'BRENNSTOFFRECHNUNG',
                'GRUNDSTEUERBESCHEID',
                'EINZELBELEG',
                'VORJAHRESABRECHNUNG',
                'ZAHLUNGSUEBERSICHT',
                'SONSTIGES',
            ]))
            ->field('quellenbezeichnung', FieldNode::text('Neutrale Quellenbezeichnung des Dokuments.', 120))
            ->field('kostenart', FieldNode::text('Bezeichnung der betroffenen Kostenart.', 200))
            ->field('betrag_cent', FieldNode::amountCent('Betrag in Cent, wie in der Quelle ausgewiesen.', true))
            ->field('einheitsbezeichnung', FieldNode::text('Betroffene Einheit oder Objekt.'))
            ->field('zeitraum_von', FieldNode::isoDate('Beginn des Zeitraums der Angabe.'))
            ->field('zeitraum_bis', FieldNode::isoDate('Ende des Zeitraums der Angabe.'))
            ->field('vorgeschlagene_behandlung', FieldNode::enumOf(
                'Vorgeschlagene Behandlung. Ausschliesslich Vorschlag, keine Entscheidung.',
                [
                    'KOSTENQUELLE',
                    'VERGLEICHSSUMME',
                    'MIETERANTEIL',
                    'DIREKTZUORDNUNG',
                    'DUBLETTENPRUEFUNG',
                    'AUSSCHLUSS',
                    'UNKLAR',
                ],
            ));

        $finding = ObjectNode::make('Konkreter Befund des Abgleichs.')
            ->field('befundart', FieldNode::enumOf('Art des Befundes.', [
                'MOEGLICHE_DUBLETTE',
                'ZEITRAUM_ABWEICHUNG',
                'SUMMENABWEICHUNG',
                'FEHLENDE_ANGABE',
                'WIDERSPRUCH',
                'NICHT_UMLAGEFAEHIGE_POSITION',
                'HINWEIS',
            ]))
            ->field('beschreibung', FieldNode::text('Sachliche Beschreibung des Befundes in deutscher Sprache.', 240))
            ->field('betroffene_kostenart', FieldNode::text('Betroffene Kostenart.', 200))
            ->field('quellenbezeichnung_a', FieldNode::text('Erste betroffene Quelle.', 120))
            ->field('quellenbezeichnung_b', FieldNode::text('Zweite betroffene Quelle, soweit vorhanden.', 120))
            ->field('differenz_cent', FieldNode::amountCent('Rechnerische Differenz in Cent, soweit bezifferbar.'));

        $root = ObjectNode::make('Abgleich mehrerer Dokumente eines Abrechnungslaufs.')
            ->field('abrechnungszeitraum_von', FieldNode::isoDate('Beginn des Abrechnungszeitraums des Laufs.'))
            ->field('abrechnungszeitraum_bis', FieldNode::isoDate('Ende des Abrechnungszeitraums des Laufs.'))
            ->field('anzahl_geprueft', FieldNode::integer('Anzahl der einbezogenen Dokumente.'))
            ->field('grundsteuer_moeglicherweise_doppelt', FieldNode::boolean(
                'true, wenn die Grundsteuer moeglicherweise sowohl separat als auch in einer anderen '
                .'Kostenliste enthalten ist. Bei unklarem Befund null.',
            ))
            ->field('heizkosten_moeglicherweise_doppelt', FieldNode::boolean(
                'true, wenn Heizkosten sowohl aus einer WEG-Summenposition als auch aus einer externen '
                .'Abrechnung stammen koennten. Bei unklarem Befund null.',
            ))
            ->listOf('matrix', $matrixRow, 'Reconciliation-Matrix nach Abschnitt 7.4.', 200)
            ->listOf('befunde', $finding, 'Konkrete Befunde des Abgleichs.', 200);

        return new SchemaDefinition(self::key(), self::version(), $root);
    }
}
