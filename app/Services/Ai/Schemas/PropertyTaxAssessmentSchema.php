<?php

declare(strict_types=1);

namespace App\Services\Ai\Schemas;

/**
 * Schema des Grundsteuerbescheids nach Abschnitt 7.3.
 *
 * Teilzeitraeume und Eigentumswechsel werden nicht geraten. Sie werden
 * erfasst, soweit sie im Bescheid stehen, und dem Nutzer zur Bestaetigung
 * vorgelegt. Ob die Grundsteuer bereits in einer anderen Kostenliste
 * enthalten ist, entscheidet die deterministische Dublettenpruefung, nicht
 * das Modell.
 */
final class PropertyTaxAssessmentSchema implements SchemaProviderInterface
{
    public static function key(): string
    {
        return 'grundsteuerbescheid';
    }

    public static function version(): string
    {
        return '1.0.0';
    }

    public static function definition(): SchemaDefinition
    {
        $installment = ObjectNode::make('Faelligkeit oder Teilbetrag aus dem Bescheid.')
            ->field('faelligkeitsdatum', FieldNode::isoDate('Faelligkeitsdatum der Teilzahlung.'))
            ->field('betrag_cent', FieldNode::amountCent('Betrag der Teilzahlung in Cent.'));

        $root = ObjectNode::make('Grundsteuerbescheid einer Gemeinde oder Stadt.')
            ->field('behoerde', FieldNode::text('Bescheiderteilende Stelle.'))
            ->field('bescheiddatum', FieldNode::isoDate('Datum des Bescheids.'))
            ->field('aktenzeichen', FieldNode::text('Aktenzeichen oder Kassenzeichen des Bescheids.', 80))
            ->field('objektanschrift', FieldNode::text('Anschrift des besteuerten Grundstuecks.', 300))
            ->field('einheitsbezeichnung', FieldNode::text('Einheit oder Wohnung, soweit im Bescheid benannt.'))
            ->field('steuerjahr', FieldNode::integer('Kalenderjahr, fuer das die Grundsteuer festgesetzt ist.'))
            ->field('zeitraum_von', FieldNode::isoDate('Beginn des Festsetzungszeitraums.'))
            ->field('zeitraum_bis', FieldNode::isoDate('Ende des Festsetzungszeitraums.'))
            ->field('jahresbetrag_cent', FieldNode::amountCent('Grundsteuer-Jahresbetrag in Cent.', true))
            ->field('grundsteuer_art', FieldNode::enumOf('Art der Grundsteuer, soweit ausgewiesen.', [
                'GRUNDSTEUER_A',
                'GRUNDSTEUER_B',
                'UNBEKANNT',
            ]))
            ->field('betrifft_teilzeitraum', FieldNode::boolean(
                'true, wenn der Bescheid ausdruecklich nur einen Teil des Kalenderjahres betrifft.',
            ))
            ->field('eigentumswechsel_erwaehnt', FieldNode::boolean(
                'true, wenn der Bescheid einen Eigentumswechsel oder eine Zurechnungsfortschreibung erwaehnt.',
            ))
            ->field('sonstige_abgaben_enthalten', FieldNode::boolean(
                'true, wenn der Bescheid neben der Grundsteuer weitere Abgaben ausweist, zum Beispiel '
                .'Muell, Strassenreinigung oder Niederschlagswasser.',
            ))
            ->listOf('faelligkeiten', $installment, 'Im Bescheid ausgewiesene Faelligkeiten.', 12);

        return new SchemaDefinition(self::key(), self::version(), $root);
    }
}
