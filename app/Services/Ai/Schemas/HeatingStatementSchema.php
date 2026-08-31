<?php

declare(strict_types=1);

namespace App\Services\Ai\Schemas;

/**
 * Schema der externen Heizkostenabrechnung nach Abschnitt 12.3 Fall A.
 *
 * Ob die CO2-Kostenaufteilung bereits enthalten ist, wird erfasst und nicht
 * angenommen. Ein unbekannter Status erzeugt eine Pruefaufgabe. Die
 * Einzelbetraege duerfen nicht zusaetzlich aus einer WEG-Summenposition ein
 * zweites Mal angesetzt werden (Abschnitt 7.4).
 */
final class HeatingStatementSchema implements SchemaProviderInterface
{
    public static function key(): string
    {
        return 'heizkostenabrechnung';
    }

    public static function version(): string
    {
        return '1.0.0';
    }

    public static function definition(): SchemaDefinition
    {
        $unitShare = ObjectNode::make('Anteil einer Einheit an der Heizkostenabrechnung.')
            ->field('einheitsbezeichnung', FieldNode::text('Bezeichnung der Einheit.'))
            ->field('nutzer_name', FieldNode::text('Name des Nutzers, soweit ausgewiesen.', 300))
            ->field('nutzungszeitraum_von', FieldNode::isoDate('Beginn des Nutzungszeitraums.'))
            ->field('nutzungszeitraum_bis', FieldNode::isoDate('Ende des Nutzungszeitraums.'))
            ->field('heizung_grundkosten_cent', FieldNode::amountCent('Grundkosten Heizung in Cent.'))
            ->field('heizung_verbrauchskosten_cent', FieldNode::amountCent('Verbrauchskosten Heizung in Cent.'))
            ->field('warmwasser_grundkosten_cent', FieldNode::amountCent('Grundkosten Warmwasser in Cent.'))
            ->field('warmwasser_verbrauchskosten_cent', FieldNode::amountCent('Verbrauchskosten Warmwasser in Cent.'))
            ->field('co2_kosten_anteil_cent', FieldNode::amountCent('Ausgewiesener CO2-Kostenanteil in Cent.'))
            ->field('summe_cent', FieldNode::amountCent('Gesamtsumme der Einheit in Cent.', true))
            ->field('verbrauch_heizung', FieldNode::decimal('Verbrauchswert Heizung als Dezimalzeichenkette.'))
            ->field('verbrauch_heizung_einheit', FieldNode::text('Masseinheit des Heizungsverbrauchs, zum Beispiel kWh.', 20))
            ->field('verbrauch_warmwasser', FieldNode::decimal('Verbrauchswert Warmwasser als Dezimalzeichenkette.'))
            ->field('verbrauch_warmwasser_einheit', FieldNode::text('Masseinheit des Warmwasserverbrauchs, zum Beispiel m3.', 20))
            ->field('vorauszahlungen_cent', FieldNode::amountCent('In der Heizkostenabrechnung beruecksichtigte Vorauszahlungen in Cent.'));

        $root = ObjectNode::make('Heizkostenabrechnung eines externen Abrechnungsdienstes.')
            ->field('abrechnungsdienst', FieldNode::text('Name des Abrechnungsdienstes.'))
            ->field('abrechnungsnummer', FieldNode::text('Abrechnungs- oder Kundennummer.', 80))
            ->field('objektanschrift', FieldNode::text('Anschrift des Objekts.', 300))
            ->field('abrechnungszeitraum_von', FieldNode::isoDate('Beginn des Abrechnungszeitraums.'))
            ->field('abrechnungszeitraum_bis', FieldNode::isoDate('Ende des Abrechnungszeitraums.'))
            ->field('gesamtkosten_heizung_cent', FieldNode::amountCent('Gesamtkosten Heizung in Cent.', true))
            ->field('gesamtkosten_warmwasser_cent', FieldNode::amountCent('Gesamtkosten Warmwasser in Cent.', true))
            ->field('gesamtkosten_summe_cent', FieldNode::amountCent('Gesamtsumme aller abgerechneten Kosten in Cent.', true))
            ->field('betriebsstrom_cent', FieldNode::amountCent('Anteil Betriebsstrom in Cent, soweit ausgewiesen.'))
            ->field('brennstoffkosten_cent', FieldNode::amountCent('Brennstoffkosten in Cent, soweit ausgewiesen.'))
            ->field('brennstoffbestand_anfang', FieldNode::decimal('Brennstoffbestand am Anfang des Zeitraums.'))
            ->field('brennstoffbestand_ende', FieldNode::decimal('Brennstoffbestand am Ende des Zeitraums.'))
            ->field('grundkostenanteil_prozent', FieldNode::decimal('Grundkostenanteil in Prozent, soweit ausgewiesen.'))
            ->field('co2_kostenaufteilung_status', FieldNode::enumOf(
                'Status der CO2-Kostenaufteilung. UNBEKANNT erzeugt eine Pruefaufgabe.',
                ['ENTHALTEN', 'NICHT_ENTHALTEN', 'UNBEKANNT'],
            ))
            ->field('co2_kosten_gesamt_cent', FieldNode::amountCent('Gesamte CO2-Kosten in Cent, soweit ausgewiesen.'))
            ->field('anzahl_einheiten', FieldNode::integer('Anzahl der abgerechneten Einheiten.'))
            ->listOf('einheiten', $unitShare, 'Anteile je Einheit.', 400);

        return new SchemaDefinition(self::key(), self::version(), $root);
    }
}
