<?php

declare(strict_types=1);

namespace App\Services\Ai\Schemas;

/**
 * Schema der WEG-Hausgeldabrechnung nach Abschnitt 7.1.
 *
 * Verbindlich getrennt auszuweisen sind insbesondere
 * Hausgeldvorauszahlungen, Abrechnungsspitze, Ruecklagenzufuehrung,
 * Ruecklagenentnahme, Verwalterverguetung, Bank- und
 * Finanzierungskosten, Instandhaltung, Reparaturen, Rechtskosten und nicht
 * naeher bezeichnete Sammelpositionen. Diese Werte duerfen nicht als
 * Mietnebenkosten uebernommen werden (Abschnitt 7.2).
 *
 * Die Kennzeichnung "umlagefaehig" durch den Verwalter ist ein Vorschlag,
 * keine Rechtsfreigabe. Sie wird daher als eigenes Feld erfasst und mit
 * Mietvertrag, BetrKV-Kategorie und Detailbelegen abgeglichen.
 */
final class CondominiumStatementSchema implements SchemaProviderInterface
{
    public static function key(): string
    {
        return 'hausgeldabrechnung';
    }

    public static function version(): string
    {
        return '1.0.0';
    }

    public static function definition(): SchemaDefinition
    {
        $costLine = ObjectNode::make('Kostenart der Hausgeldabrechnung.')
            ->field('bezeichnung', FieldNode::text('Bezeichnung der Kostenart, wie in der Abrechnung ausgewiesen.', 200))
            ->field('gesamtkosten_cent', FieldNode::amountCent('Gesamtkosten der WEG fuer diese Kostenart in Cent.', true))
            ->field('anteil_einheit_cent', FieldNode::amountCent('Auf die Einheit entfallender Anteil in Cent.', true))
            ->field('weg_verteilerschluessel', FieldNode::text('Verteilerschluessel der WEG fuer diese Kostenart.', 120))
            ->field('verwalter_kennzeichnung_umlagefaehig', FieldNode::boolean(
                'true, wenn der Verwalter die Kostenart als umlagefaehig gekennzeichnet hat. '
                .'Das ist ein Vorschlag, keine Rechtsfreigabe.',
            ))
            ->field('kategorie', FieldNode::enumOf(
                'Fachliche Einordnung der Kostenart. Positionen ohne erkennbare Kostenart sind SAMMELPOSITION_UNBEZEICHNET.',
                [
                    'BETRIEBSKOSTEN',
                    'HEIZUNG_WARMWASSER',
                    'RUECKLAGENZUFUEHRUNG',
                    'RUECKLAGENENTNAHME',
                    'INSTANDHALTUNG_INSTANDSETZUNG',
                    'REPARATUR',
                    'VERWALTERVERGUETUNG',
                    'BANK_FINANZIERUNGSKOSTEN',
                    'RECHTS_PROZESSKOSTEN',
                    'SAMMELPOSITION_UNBEZEICHNET',
                    'SONSTIGES',
                ],
            ));

        $root = ObjectNode::make('WEG-Hausgeldabrechnung, Gesamt- oder Einzelabrechnung.')
            ->field('abrechnungsart', FieldNode::enumOf('Art der Abrechnung.', [
                'GESAMTABRECHNUNG',
                'EINZELABRECHNUNG',
                'WIRTSCHAFTSPLAN',
            ]))
            ->field('weg_bezeichnung', FieldNode::text('Bezeichnung der Wohnungseigentuemergemeinschaft.', 200))
            ->field('objektanschrift', FieldNode::text('Anschrift des Objekts.', 300))
            ->field('verwalter', FieldNode::text('Verwaltende Gesellschaft, soweit ausgewiesen.'))
            ->field('abrechnungszeitraum_von', FieldNode::isoDate('Beginn des Abrechnungszeitraums.'))
            ->field('abrechnungszeitraum_bis', FieldNode::isoDate('Ende des Abrechnungszeitraums.'))
            ->field('einheitsbezeichnung', FieldNode::text('Bezeichnung der Einheit.'))
            ->field('wohnungsnummer', FieldNode::text('Wohnungsnummer.', 40))
            ->field('miteigentumsanteil', FieldNode::decimal('Miteigentumsanteil der Einheit, als Dezimalzeichenkette.'))
            ->field('miteigentumsanteil_nenner', FieldNode::decimal('Nenner der Miteigentumsanteile, soweit ausgewiesen.'))
            ->field('wohnflaeche_qm', FieldNode::decimal('Wohnflaeche der Einheit in Quadratmetern.'))
            ->field('hausgeldvorauszahlungen_cent', FieldNode::amountCent(
                'Geleistete Hausgeldvorauszahlungen des Eigentuemers in Cent. '
                .'Niemals als Mietnebenkosten uebernehmen.',
            ))
            ->field('abrechnungsspitze_cent', FieldNode::amountCent(
                'Abrechnungsspitze gegenueber der WEG in Cent. Positiv bedeutet Nachzahlung, negativ Guthaben. '
                .'Niemals als Pauschalbetrag in die Mietnebenkosten uebernehmen.',
            ))
            ->field('ruecklagenzufuehrung_cent', FieldNode::amountCent('Zufuehrung zur Erhaltungsruecklage in Cent.'))
            ->field('ruecklagenentnahme_cent', FieldNode::amountCent('Entnahme aus der Erhaltungsruecklage in Cent.'))
            ->field('verwalterverguetung_cent', FieldNode::amountCent('Verwalterverguetung in Cent.'))
            ->field('bank_finanzierungskosten_cent', FieldNode::amountCent('Bank- und Finanzierungskosten in Cent.'))
            ->field('instandhaltung_reparatur_cent', FieldNode::amountCent('Instandhaltungs-, Instandsetzungs- und Reparaturkosten in Cent.'))
            ->field('rechts_prozesskosten_cent', FieldNode::amountCent('Rechts- und Prozesskosten in Cent.'))
            ->field('heizkosten_anteil_einheit_cent', FieldNode::amountCent('Auf die Einheit entfallende Heizkosten in Cent.'))
            ->field('warmwasserkosten_anteil_einheit_cent', FieldNode::amountCent('Auf die Einheit entfallende Warmwasserkosten in Cent.'))
            ->field('gesamtkosten_alle_kostenarten_cent', FieldNode::amountCent('Summe aller Gesamtkosten der WEG in Cent, soweit ausgewiesen.'))
            ->field('summe_anteil_einheit_cent', FieldNode::amountCent('Summe der auf die Einheit entfallenden Anteile in Cent, soweit ausgewiesen.'))
            ->field('grundsteuer_enthalten', FieldNode::boolean(
                'true, wenn die Grundsteuer erkennbar in der Hausgeldabrechnung enthalten ist. '
                .'Bei unklarem Befund null, damit eine Pruefaufgabe entsteht.',
            ))
            ->field('kostenaufschluesselung_vorhanden', FieldNode::boolean(
                'true, wenn eine Aufschluesselung je Kostenart vorliegt. Liegt nur der monatliche Hausgeldbetrag '
                .'oder nur die Abrechnungsspitze vor, ist der Wert false.',
            ))
            ->listOf('kostenarten', $costLine, 'Alle in der Abrechnung ausgewiesenen Kostenarten.', 200);

        return new SchemaDefinition(self::key(), self::version(), $root);
    }
}
