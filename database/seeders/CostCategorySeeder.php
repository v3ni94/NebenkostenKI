<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AllocationKeyType;
use App\Enums\ApportionmentStatus;
use App\Enums\Paragraph35aType;
use App\Models\CostCategory;
use Illuminate\Database\Seeder;

/**
 * Standard-Kostenkategorien nach Abschnitt 12.1 und 12.2 der Spezifikation.
 *
 * VERSIONIERUNG: Jede Kategorie tragt einen Gueltigkeitszeitraum. Der Seeder legt
 * die erste Fassung mit valid_from 01.01.2004 an, dem Inkrafttreten der
 * Betriebskostenverordnung. Bei einer spaeteren Gesetzes- oder Bewertungsaenderung
 * wird die alte Fassung mit valid_to geschlossen und eine neue Fassung mit
 * demselben code und neuem valid_from angelegt. Kostenpositionen verweisen auf die
 * zum Abrechnungszeitraum gueltige ULID, damit alte Abrechnungen reproduzierbar
 * bleiben.
 *
 * RECHTLICHER HINWEIS: Die BetrKV-Referenz ist ein Textfeld zur Orientierung und
 * die Umlagebewertung ein fachlicher Vorschlag. Beides ist keine
 * Einzelfall-Rechtsberatung und keine Rechtsfreigabe. Die Verantwortung fuer die
 * Abrechnung bleibt beim Vermieter.
 *
 * Der Seeder ist idempotent und kann nach einer Ergaenzung erneut laufen.
 */
class CostCategorySeeder extends Seeder
{
    /**
     * Beginn der ersten Kategoriefassung.
     */
    private const INITIAL_VALID_FROM = '2004-01-01';

    public function run(): void
    {
        $sortOrder = 0;

        foreach ($this->categories() as $category) {
            $sortOrder += 10;

            $attributes = [
                'organization_id' => null,
                'name' => $category['name'],
                'betrkv_reference' => $category['betrkv_reference'],
                'apportionment_status' => $category['apportionment_status'],
                'default_allocation_key_type' => $category['default_allocation_key_type'],
                'paragraph_35a_type' => $category['paragraph_35a_type'] ?? Paragraph35aType::NONE,
                'excluded_from_apportionment_by_default' => $category['excluded'] ?? false,
                'requires_contract_basis' => $category['requires_contract_basis'] ?? false,
                'requires_manual_review' => $category['requires_manual_review'] ?? false,
                'is_heating_related' => $category['is_heating_related'] ?? false,
                'is_warm_water_related' => $category['is_warm_water_related'] ?? false,
                'supports_labor_share' => $category['supports_labor_share'] ?? false,
                'is_custom' => false,
                'warning_note' => $category['warning_note'] ?? null,
                'description' => $category['description'] ?? null,
                'sort_order' => $sortOrder,
                'valid_to' => null,
            ];

            // whereDate arbeitet auf MariaDB und SQLite gleich. Ein direkter
            // Vergleich waere nicht portabel, weil SQLite Datumswerte als
            // Zeitstempel ablegt.
            $existing = CostCategory::query()
                ->where('code', $category['code'])
                ->whereDate('valid_from', self::INITIAL_VALID_FROM)
                ->first();

            if ($existing !== null) {
                $existing->fill($attributes)->save();

                continue;
            }

            CostCategory::query()->create(array_merge($attributes, [
                'code' => $category['code'],
                'valid_from' => self::INITIAL_VALID_FROM,
            ]));
        }
    }

    /**
     * Kategoriedefinitionen in Anzeigereihenfolge.
     *
     * @return list<array<string, mixed>>
     */
    private function categories(): array
    {
        return array_merge($this->apportionableCategories(), $this->excludedCategories());
    }

    /**
     * Abschnitt 12.1: standardmaessig umlagefaehige Betriebskosten.
     *
     * @return list<array<string, mixed>>
     */
    private function apportionableCategories(): array
    {
        return [
            [
                'code' => 'GRUNDSTEUER',
                'name' => 'Grundsteuer',
                'betrkv_reference' => 'BetrKV § 2 Nr. 1, laufende öffentliche Lasten des Grundstücks',
                'apportionment_status' => ApportionmentStatus::UMLAGEFAEHIG,
                'default_allocation_key_type' => AllocationKeyType::WOHNFLAECHE,
                'requires_manual_review' => true,
                'warning_note' => 'Bitte prüfen, ob die Grundsteuer bereits in der Hausgeldabrechnung enthalten ist. Bei möglicher Dublette erfolgt keine Addition.',
            ],
            [
                'code' => 'WASSERVERSORGUNG',
                'name' => 'Wasserversorgung',
                'betrkv_reference' => 'BetrKV § 2 Nr. 2',
                'apportionment_status' => ApportionmentStatus::UMLAGEFAEHIG,
                'default_allocation_key_type' => AllocationKeyType::VERBRAUCH,
                'warning_note' => 'Ohne geeignete Zählerwerte ist der Verbrauchsschlüssel nicht anwendbar. Dann ist ein vereinbarter Ersatzschlüssel zu bestätigen.',
            ],
            [
                'code' => 'ENTWAESSERUNG',
                'name' => 'Entwässerung und Abwasser',
                'betrkv_reference' => 'BetrKV § 2 Nr. 3',
                'apportionment_status' => ApportionmentStatus::UMLAGEFAEHIG,
                'default_allocation_key_type' => AllocationKeyType::VERBRAUCH,
            ],
            [
                'code' => 'NIEDERSCHLAGSWASSER',
                'name' => 'Niederschlagswasser',
                'betrkv_reference' => 'BetrKV § 2 Nr. 3, Entwässerung des Grundstücks',
                'apportionment_status' => ApportionmentStatus::UMLAGEFAEHIG,
                'default_allocation_key_type' => AllocationKeyType::WOHNFLAECHE,
            ],
            [
                'code' => 'HEIZUNG',
                'name' => 'Heizung',
                'betrkv_reference' => 'BetrKV § 2 Nr. 4, Verteilung nach HeizkostenV',
                'apportionment_status' => ApportionmentStatus::UMLAGEFAEHIG,
                'default_allocation_key_type' => AllocationKeyType::VERBRAUCH,
                'is_heating_related' => true,
                'requires_manual_review' => true,
                'warning_note' => 'Die Verteilung folgt der Heizkostenverordnung. Liegt eine externe Heizkostenabrechnung vor, dürfen deren Beträge nicht zusätzlich aus einer Summenposition angesetzt werden.',
            ],
            [
                'code' => 'WARMWASSER',
                'name' => 'Warmwasser',
                'betrkv_reference' => 'BetrKV § 2 Nr. 5, Verteilung nach HeizkostenV',
                'apportionment_status' => ApportionmentStatus::UMLAGEFAEHIG,
                'default_allocation_key_type' => AllocationKeyType::VERBRAUCH,
                'is_heating_related' => true,
                'is_warm_water_related' => true,
                'requires_manual_review' => true,
            ],
            [
                'code' => 'AUFZUG',
                'name' => 'Aufzug',
                'betrkv_reference' => 'BetrKV § 2 Nr. 7',
                'apportionment_status' => ApportionmentStatus::UMLAGEFAEHIG,
                'default_allocation_key_type' => AllocationKeyType::WOHNFLAECHE,
                'paragraph_35a_type' => Paragraph35aType::HANDWERKERLEISTUNG,
                'supports_labor_share' => true,
                'warning_note' => 'Wartungskosten sind laufende Betriebskosten. Reparatur- und Instandsetzungsanteile sind auszuschließen.',
            ],
            [
                'code' => 'STRASSENREINIGUNG',
                'name' => 'Straßenreinigung',
                'betrkv_reference' => 'BetrKV § 2 Nr. 8',
                'apportionment_status' => ApportionmentStatus::UMLAGEFAEHIG,
                'default_allocation_key_type' => AllocationKeyType::WOHNFLAECHE,
            ],
            [
                'code' => 'MUELLBESEITIGUNG',
                'name' => 'Müllbeseitigung',
                'betrkv_reference' => 'BetrKV § 2 Nr. 8',
                'apportionment_status' => ApportionmentStatus::UMLAGEFAEHIG,
                'default_allocation_key_type' => AllocationKeyType::WOHNFLAECHE,
            ],
            [
                'code' => 'GEBAEUDEREINIGUNG',
                'name' => 'Gebäudereinigung',
                'betrkv_reference' => 'BetrKV § 2 Nr. 9',
                'apportionment_status' => ApportionmentStatus::UMLAGEFAEHIG,
                'default_allocation_key_type' => AllocationKeyType::WOHNFLAECHE,
                'paragraph_35a_type' => Paragraph35aType::HAUSHALTSNAHE_DIENSTLEISTUNG,
                'supports_labor_share' => true,
            ],
            [
                'code' => 'UNGEZIEFERBEKAEMPFUNG',
                'name' => 'Ungezieferbekämpfung',
                'betrkv_reference' => 'BetrKV § 2 Nr. 9',
                'apportionment_status' => ApportionmentStatus::UMLAGEFAEHIG,
                'default_allocation_key_type' => AllocationKeyType::WOHNFLAECHE,
                'paragraph_35a_type' => Paragraph35aType::HAUSHALTSNAHE_DIENSTLEISTUNG,
                'supports_labor_share' => true,
                'requires_manual_review' => true,
                'warning_note' => 'Nur laufende Vorbeugemaßnahmen gelten als Betriebskosten. Eine einmalige Schadensbeseitigung ist gesondert zu prüfen.',
            ],
            [
                'code' => 'GARTENPFLEGE',
                'name' => 'Gartenpflege',
                'betrkv_reference' => 'BetrKV § 2 Nr. 10',
                'apportionment_status' => ApportionmentStatus::UMLAGEFAEHIG,
                'default_allocation_key_type' => AllocationKeyType::WOHNFLAECHE,
                'paragraph_35a_type' => Paragraph35aType::HAUSHALTSNAHE_DIENSTLEISTUNG,
                'supports_labor_share' => true,
                'warning_note' => 'Neuanlagen und Ersatzpflanzungen größeren Umfangs sind gesondert zu prüfen.',
            ],
            [
                'code' => 'ALLGEMEINSTROM',
                'name' => 'Allgemeinstrom und Beleuchtung',
                'betrkv_reference' => 'BetrKV § 2 Nr. 11',
                'apportionment_status' => ApportionmentStatus::UMLAGEFAEHIG,
                'default_allocation_key_type' => AllocationKeyType::WOHNFLAECHE,
            ],
            [
                'code' => 'SCHORNSTEINREINIGUNG',
                'name' => 'Schornsteinreinigung',
                'betrkv_reference' => 'BetrKV § 2 Nr. 12',
                'apportionment_status' => ApportionmentStatus::UMLAGEFAEHIG,
                'default_allocation_key_type' => AllocationKeyType::WOHNFLAECHE,
                'paragraph_35a_type' => Paragraph35aType::HANDWERKERLEISTUNG,
                'supports_labor_share' => true,
                'is_heating_related' => true,
                'warning_note' => 'Soweit die Kosten bereits in den Heizkosten enthalten sind, ist eine Doppelerfassung zu vermeiden.',
            ],
            [
                'code' => 'SACHVERSICHERUNG',
                'name' => 'Sach- und Gebäudeversicherung',
                'betrkv_reference' => 'BetrKV § 2 Nr. 13',
                'apportionment_status' => ApportionmentStatus::UMLAGEFAEHIG,
                'default_allocation_key_type' => AllocationKeyType::WOHNFLAECHE,
            ],
            [
                'code' => 'HAFTPFLICHTVERSICHERUNG',
                'name' => 'Haus- und Grundbesitzerhaftpflicht',
                'betrkv_reference' => 'BetrKV § 2 Nr. 13',
                'apportionment_status' => ApportionmentStatus::UMLAGEFAEHIG,
                'default_allocation_key_type' => AllocationKeyType::WOHNFLAECHE,
            ],
            [
                'code' => 'HAUSWART',
                'name' => 'Hauswart',
                'betrkv_reference' => 'BetrKV § 2 Nr. 14',
                'apportionment_status' => ApportionmentStatus::UMLAGEFAEHIG,
                'default_allocation_key_type' => AllocationKeyType::WOHNFLAECHE,
                'paragraph_35a_type' => Paragraph35aType::HAUSHALTSNAHE_DIENSTLEISTUNG,
                'supports_labor_share' => true,
                'requires_manual_review' => true,
                'warning_note' => 'Anteile für Instandsetzung, Instandhaltung, Verwaltung und Schönheitsreparaturen sind herauszurechnen und nicht umlagefähig.',
            ],
            [
                'code' => 'GEMEINSCHAFTSANTENNE_BREITBAND',
                'name' => 'Gemeinschaftsantenne, Breitband und Glasfaser',
                'betrkv_reference' => 'BetrKV § 2 Nr. 15',
                'apportionment_status' => ApportionmentStatus::PRUEFPFLICHTIG,
                'default_allocation_key_type' => AllocationKeyType::EINHEITEN,
                'requires_contract_basis' => true,
                'requires_manual_review' => true,
                'warning_note' => 'Die Umlagefähigkeit von Breitband- und Glasfaserkosten hängt vom Vertrag und vom Abrechnungszeitraum ab und hat sich mehrfach geändert. Bitte Mietvertrag und Zeitraum prüfen und im Zweifel rechtlich beraten lassen.',
            ],
            [
                'code' => 'WAESCHEPFLEGE',
                'name' => 'Wäschepflege',
                'betrkv_reference' => 'BetrKV § 2 Nr. 16',
                'apportionment_status' => ApportionmentStatus::UMLAGEFAEHIG,
                'default_allocation_key_type' => AllocationKeyType::EINHEITEN,
            ],
            [
                'code' => 'SONSTIGE_BETRIEBSKOSTEN',
                'name' => 'Sonstige Betriebskosten',
                'betrkv_reference' => 'BetrKV § 2 Nr. 17',
                'apportionment_status' => ApportionmentStatus::PRUEFPFLICHTIG,
                'default_allocation_key_type' => AllocationKeyType::WOHNFLAECHE,
                'requires_contract_basis' => true,
                'requires_manual_review' => true,
                'warning_note' => 'Eine Umlage ist nur bei konkreter, hinreichend bestimmter Vereinbarung im Mietvertrag möglich. Ohne erkannte Vertragsgrundlage bleibt die Position ausgeschlossen.',
            ],
            [
                'code' => 'INDIVIDUELLE_KATEGORIE',
                'name' => 'Individuelle Kategorie',
                'betrkv_reference' => null,
                'apportionment_status' => ApportionmentStatus::PRUEFPFLICHTIG,
                'default_allocation_key_type' => AllocationKeyType::WOHNFLAECHE,
                'requires_contract_basis' => true,
                'requires_manual_review' => true,
                'description' => 'Vorlage für eine selbst benannte Kategorie. Die Anwendungsschicht erzeugt daraus je Organisation eine eigene Kategorie mit global eindeutigem code.',
                'warning_note' => 'Bezeichnung, Umlagefähigkeit und Verteilerschlüssel sind vom Nutzer festzulegen und zu verantworten.',
            ],
        ];
    }

    /**
     * Abschnitt 12.2: standardmaessig nicht umlagefaehig beziehungsweise
     * pruefpflichtig, zusaetzlich die Sonderfaelle der WEG-Abrechnung aus
     * Abschnitt 7.2.
     *
     * @return list<array<string, mixed>>
     */
    private function excludedCategories(): array
    {
        return [
            [
                'code' => 'VERWALTUNGSKOSTEN',
                'name' => 'Verwaltungskosten',
                'betrkv_reference' => 'BetrKV § 1 Abs. 2 Nr. 1, keine Betriebskosten',
                'apportionment_status' => ApportionmentStatus::NICHT_UMLAGEFAEHIG,
                'default_allocation_key_type' => AllocationKeyType::WOHNFLAECHE,
                'excluded' => true,
                'requires_manual_review' => true,
                'warning_note' => 'Verwaltungskosten sind bei Wohnraummiete grundsätzlich nicht umlagefähig. Eine Änderung erfordert eine Begründung und ist keine juristische Freigabe.',
            ],
            [
                'code' => 'INSTANDHALTUNG_INSTANDSETZUNG',
                'name' => 'Instandhaltung und Instandsetzung',
                'betrkv_reference' => 'BetrKV § 1 Abs. 2 Nr. 2, keine Betriebskosten',
                'apportionment_status' => ApportionmentStatus::NICHT_UMLAGEFAEHIG,
                'default_allocation_key_type' => AllocationKeyType::WOHNFLAECHE,
                'excluded' => true,
                'requires_manual_review' => true,
                'warning_note' => 'Instandhaltung und Instandsetzung sind keine Betriebskosten und bleiben beim Eigentümer.',
            ],
            [
                'code' => 'REPARATUREN',
                'name' => 'Reparaturen',
                'betrkv_reference' => 'BetrKV § 1 Abs. 2 Nr. 2, keine Betriebskosten',
                'apportionment_status' => ApportionmentStatus::NICHT_UMLAGEFAEHIG,
                'default_allocation_key_type' => AllocationKeyType::WOHNFLAECHE,
                'excluded' => true,
                'requires_manual_review' => true,
                'warning_note' => 'Reparaturen sind Instandsetzung und nicht umlagefähig. Nur laufende Wartung kann Betriebskosten darstellen.',
            ],
            [
                'code' => 'BANK_FINANZIERUNGSKOSTEN',
                'name' => 'Bank- und Finanzierungskosten',
                'betrkv_reference' => 'Nicht im Katalog der BetrKV § 2 enthalten',
                'apportionment_status' => ApportionmentStatus::NICHT_UMLAGEFAEHIG,
                'default_allocation_key_type' => AllocationKeyType::WOHNFLAECHE,
                'excluded' => true,
                'warning_note' => 'Kontoführung, Zinsen und Finanzierungskosten sind nicht umlagefähig.',
            ],
            [
                'code' => 'RECHTSKOSTEN',
                'name' => 'Rechts- und Prozesskosten',
                'betrkv_reference' => 'Nicht im Katalog der BetrKV § 2 enthalten',
                'apportionment_status' => ApportionmentStatus::NICHT_UMLAGEFAEHIG,
                'default_allocation_key_type' => AllocationKeyType::WOHNFLAECHE,
                'excluded' => true,
                'warning_note' => 'Rechts- und Prozesskosten sind nicht umlagefähig.',
            ],
            [
                'code' => 'RUECKLAGENZUFUEHRUNG',
                'name' => 'Zuführung zur Erhaltungsrücklage',
                'betrkv_reference' => 'Keine Betriebskosten im Sinne der BetrKV',
                'apportionment_status' => ApportionmentStatus::NICHT_UMLAGEFAEHIG,
                'default_allocation_key_type' => AllocationKeyType::MEA,
                'excluded' => true,
                'warning_note' => 'Die Zuführung zur Erhaltungsrücklage ist keine Mietnebenkostenposition und wird nicht umgelegt.',
            ],
            [
                'code' => 'RUECKLAGENENTNAHME',
                'name' => 'Entnahme aus der Erhaltungsrücklage',
                'betrkv_reference' => 'Bewertung richtet sich nach der zugrunde liegenden Kostenart',
                'apportionment_status' => ApportionmentStatus::PRUEFPFLICHTIG,
                'default_allocation_key_type' => AllocationKeyType::MEA,
                'excluded' => true,
                'requires_manual_review' => true,
                'warning_note' => 'Eine Entnahme ist nur dann relevant, wenn die zugrunde liegende Kostenart geprüft und umlagefähig ist. Ohne Nachweis bleibt die Position ausgeschlossen.',
            ],
            [
                'code' => 'NEUANSCHAFFUNG_MODERNISIERUNG',
                'name' => 'Neuanschaffung und Modernisierung',
                'betrkv_reference' => 'Keine laufenden Betriebskosten im Sinne der BetrKV § 2',
                'apportionment_status' => ApportionmentStatus::PRUEFPFLICHTIG,
                'default_allocation_key_type' => AllocationKeyType::WOHNFLAECHE,
                'excluded' => true,
                'requires_manual_review' => true,
                'warning_note' => 'Neuanschaffung und Modernisierung sind keine laufenden Betriebskosten. Eine Umlage über die Betriebskostenabrechnung ist nicht vorgesehen.',
            ],
            [
                'code' => 'WEG_HAUSGELDVORAUSZAHLUNG',
                'name' => 'Hausgeldvorauszahlung der WEG',
                'betrkv_reference' => 'Keine Kostenart, sondern Vorauszahlung des Eigentümers',
                'apportionment_status' => ApportionmentStatus::NICHT_UMLAGEFAEHIG,
                'default_allocation_key_type' => AllocationKeyType::MEA,
                'excluded' => true,
                'requires_manual_review' => true,
                'warning_note' => 'Hausgeldvorauszahlungen sind keine Betriebskosten und dürfen nicht als Mietnebenkosten übernommen werden.',
            ],
            [
                'code' => 'WEG_ABRECHNUNGSSPITZE',
                'name' => 'Abrechnungsspitze der WEG',
                'betrkv_reference' => 'Keine Kostenart, sondern Saldo gegenüber der WEG',
                'apportionment_status' => ApportionmentStatus::NICHT_UMLAGEFAEHIG,
                'default_allocation_key_type' => AllocationKeyType::MEA,
                'excluded' => true,
                'requires_manual_review' => true,
                'warning_note' => 'Nachzahlung oder Guthaben gegenüber der WEG darf nicht als Pauschalbetrag umgelegt werden. Erforderlich ist die Kostenaufschlüsselung der Einzelabrechnung.',
            ],
        ];
    }
}
