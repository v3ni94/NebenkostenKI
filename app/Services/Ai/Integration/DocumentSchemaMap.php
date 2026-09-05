<?php

declare(strict_types=1);

namespace App\Services\Ai\Integration;

use App\Enums\AiCallPurpose;
use App\Enums\DocumentType;
use App\Services\Ai\Schemas\SchemaRegistry;

/**
 * Zuordnung Dokumentart zu Extraktionsschema und Aufrufzweck.
 *
 * Abschnitt 6.2 kennt deutlich mehr Dokumentarten als Abschnitt 13.7
 * Kernschemata. Alle belegartigen Unterlagen, also Bescheide, Rechnungen,
 * Gutschriften und Stornos, werden deshalb gegen dasselbe Schema
 * "rechnung_bescheid" ausgewertet. Das ist bewusst so: ein eigenes Schema je
 * Kostenart wuerde die Schemapflege vervielfachen, ohne fachlich andere Felder
 * zu liefern.
 *
 * SONSTIGES hat kein Schema. Es wird nichts geraten; der Nutzer ordnet die
 * Unterlage manuell zu (Grundsatz 5).
 *
 * Zweck und damit Modellwahl folgen Abschnitt 13.8: Vertraege und
 * Vorjahresabrechnungen laufen ueber die Analysemethoden der KI-Schicht und
 * damit ueber das leistungsfaehigere Modell, alles Uebrige ueber die einfache
 * Extraktion.
 */
final class DocumentSchemaMap
{
    public function __construct(private readonly SchemaRegistry $schemas) {}

    /**
     * Schemaschluessel der Dokumentart oder null, wenn keine automatische
     * Auswertung vorgesehen ist.
     */
    public function schemaKeyFor(DocumentType $type): ?string
    {
        $key = match ($type) {
            DocumentType::WEG_HAUSGELDABRECHNUNG_GESAMT,
            DocumentType::WEG_HAUSGELDABRECHNUNG_EINZEL,
            DocumentType::WEG_WIRTSCHAFTSPLAN => 'hausgeldabrechnung',

            DocumentType::GRUNDSTEUERBESCHEID => 'grundsteuerbescheid',

            DocumentType::HEIZKOSTENABRECHNUNG => 'heizkostenabrechnung',

            DocumentType::MIETVERTRAG,
            DocumentType::MIETVERTRAG_NACHTRAG => 'mietvertrag',

            DocumentType::VORJAHRESABRECHNUNG => 'vorjahresabrechnung',

            DocumentType::MIETER_EINHEITENLISTE => 'mieter_einheitenliste',

            DocumentType::ZAEHLERLISTE_ABLESEPROTOKOLL => 'zaehlerwerte',

            DocumentType::KONTOAUSZUG_ZAHLUNGSUEBERSICHT => 'zahlungsuebersicht',

            DocumentType::WASSER_ABWASSERBESCHEID,
            DocumentType::NIEDERSCHLAGSWASSERBESCHEID,
            DocumentType::STRASSENREINIGUNGSBESCHEID,
            DocumentType::MUELLGEBUEHRENBESCHEID,
            DocumentType::VERSICHERUNGSRECHNUNG,
            DocumentType::HAUSMEISTER_REINIGUNG_GARTEN,
            DocumentType::ALLGEMEINSTROM,
            DocumentType::AUFZUG_WARTUNG_SCHORNSTEIN,
            DocumentType::ENERGIE_BRENNSTOFFRECHNUNG,
            DocumentType::RECHNUNG,
            DocumentType::GUTSCHRIFT,
            DocumentType::STORNO => 'rechnung_bescheid',

            DocumentType::SONSTIGES => null,
        };

        // Ein Schemaschluessel ohne hinterlegtes Schema waere ein
        // Konfigurationsfehler und darf nicht zu einem Provideraufruf fuehren.
        if ($key === null || ! $this->schemas->has($key)) {
            return null;
        }

        return $key;
    }

    /**
     * Zweck des Aufrufs. Er steuert Modellwahl, Prompt und Kostenkontrolle.
     */
    public function purposeFor(DocumentType $type): AiCallPurpose
    {
        return match ($type) {
            DocumentType::MIETVERTRAG,
            DocumentType::MIETVERTRAG_NACHTRAG => AiCallPurpose::VERTRAGSANALYSE,
            DocumentType::VORJAHRESABRECHNUNG => AiCallPurpose::VORJAHRESANALYSE,
            default => AiCallPurpose::EXTRAKTION,
        };
    }

    public function isAmendment(DocumentType $type): bool
    {
        return $type === DocumentType::MIETVERTRAG_NACHTRAG;
    }
}
