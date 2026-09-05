<?php

declare(strict_types=1);

namespace App\Application\Reconciliation;

use App\Models\BillingRun;
use App\Models\CostCategory;
use Illuminate\Support\Carbon;

/**
 * Ordnet einem Kategorievorschlag die zum Abrechnungszeitraum gueltige
 * Kategoriefassung zu.
 *
 * Die Zuordnung ist ein Vorschlag und keine Rechtsfreigabe. Laesst sich keine
 * Kategorie sicher bestimmen, bleibt die Position ohne Kategorie und ist
 * pruefpflichtig. Es wird nicht geraten.
 */
final class CategoryResolver
{
    /**
     * Stichworte je Kategoriecode. Die Liste ist bewusst knapp und enthaelt
     * nur eindeutige Begriffe. Ein unklarer Text fuehrt zu keinem Vorschlag.
     *
     * @var array<string, list<string>>
     */
    private const array KEYWORDS = [
        'GRUNDSTEUER' => ['grundsteuer', 'grundbesitzabgabe'],
        'WASSERVERSORGUNG' => ['wasserversorgung', 'trinkwasser', 'frischwasser', 'wassergeld'],
        'ENTWAESSERUNG' => ['entwaesserung', 'entwässerung', 'abwasser', 'schmutzwasser', 'kanalgebuehr', 'kanalgebühr'],
        'NIEDERSCHLAGSWASSER' => ['niederschlagswasser', 'regenwasser', 'oberflaechenwasser'],
        'HEIZUNG' => ['heizkosten', 'heizung', 'fernwaerme', 'fernwärme', 'brennstoff', 'heizoel', 'heizöl'],
        'WARMWASSER' => ['warmwasser', 'warmwasserkosten'],
        'AUFZUG' => ['aufzug', 'fahrstuhl', 'lift'],
        'STRASSENREINIGUNG' => ['strassenreinigung', 'straßenreinigung', 'winterdienst'],
        'MUELLBESEITIGUNG' => ['muellbeseitigung', 'müllbeseitigung', 'muellgebuehr', 'müllgebühr', 'abfallgebuehr', 'abfallgebühr', 'restmuell', 'restmüll'],
        'GEBAEUDEREINIGUNG' => ['gebaeudereinigung', 'gebäudereinigung', 'treppenhausreinigung', 'hausreinigung'],
        'UNGEZIEFERBEKAEMPFUNG' => ['ungezieferbekaempfung', 'schaedlingsbekaempfung', 'schädlingsbekämpfung'],
        'GARTENPFLEGE' => ['gartenpflege', 'gartenarbeit', 'gruenpflege', 'grünpflege', 'gaertner', 'gärtner'],
        'ALLGEMEINSTROM' => ['allgemeinstrom', 'hausstrom', 'beleuchtung', 'treppenhausbeleuchtung'],
        'SCHORNSTEINREINIGUNG' => ['schornsteinreinigung', 'schornsteinfeger', 'kaminfeger'],
        'SACHVERSICHERUNG' => ['gebaeudeversicherung', 'gebäudeversicherung', 'sachversicherung', 'wohngebaeudeversicherung'],
        'HAFTPFLICHTVERSICHERUNG' => ['haftpflichtversicherung', 'haus- und grundbesitzerhaftpflicht'],
        'HAUSWART' => ['hauswart', 'hausmeister'],
        'GEMEINSCHAFTSANTENNE_BREITBAND' => ['gemeinschaftsantenne', 'breitband', 'kabelanschluss', 'glasfaser'],
        'WAESCHEPFLEGE' => ['waeschepflege', 'wäschepflege', 'waschkueche', 'waschküche'],
        'VERWALTUNGSKOSTEN' => ['verwaltervergütung', 'verwalterverguetung', 'verwaltungskosten', 'verwalterhonorar'],
        'INSTANDHALTUNG_INSTANDSETZUNG' => ['instandhaltung', 'instandsetzung'],
        'REPARATUREN' => ['reparatur'],
        'BANK_FINANZIERUNGSKOSTEN' => ['bankkosten', 'kontofuehrung', 'kontoführung', 'finanzierungskosten', 'zinsen'],
        'RECHTSKOSTEN' => ['rechtskosten', 'prozesskosten', 'anwaltskosten', 'gerichtskosten'],
        'RUECKLAGENZUFUEHRUNG' => ['ruecklagenzufuehrung', 'rücklagenzuführung', 'erhaltungsruecklage'],
        'RUECKLAGENENTNAHME' => ['ruecklagenentnahme', 'rücklagenentnahme'],
        'WEG_HAUSGELDVORAUSZAHLUNG' => ['hausgeldvorauszahlung', 'hausgeldvorschuss'],
        'WEG_ABRECHNUNGSSPITZE' => ['abrechnungsspitze'],
    ];

    /**
     * @var array<string, CostCategory|null>
     */
    private array $cache = [];

    /**
     * Kategoriefassung zu einem Code, gueltig zum Beginn des
     * Abrechnungszeitraums.
     */
    public function byCode(BillingRun $billingRun, ?string $code): ?CostCategory
    {
        if ($code === null || $code === '') {
            return null;
        }

        $key = $code.'|'.$this->referenceDate($billingRun);

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $category = CostCategory::query()
            ->where('code', $code)
            ->where(function ($query) use ($billingRun): void {
                $query->whereNull('organization_id')
                    ->orWhere('organization_id', $billingRun->getAttribute('organization_id'));
            })
            ->validOn($this->referenceDate($billingRun))
            ->orderByDesc('valid_from')
            ->first();

        $this->cache[$key] = $category instanceof CostCategory ? $category : null;

        return $this->cache[$key];
    }

    /**
     * Kategorievorschlag aus einem freien Text, zum Beispiel der Bezeichnung
     * der Kostenart. Ohne eindeutigen Treffer gibt es keinen Vorschlag.
     */
    public function proposeCode(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $normalized = mb_strtolower($text);
        $treffer = [];

        foreach (self::KEYWORDS as $code => $words) {
            foreach ($words as $word) {
                if (str_contains($normalized, $word)) {
                    $treffer[$code] = true;

                    break;
                }
            }
        }

        $codes = array_keys($treffer);

        // Mehrere gleichwertige Treffer sind nicht eindeutig. Ein Beispiel ist
        // "Heizung und Warmwasser". Dann entscheidet der Nutzer.
        return count($codes) === 1 ? $codes[0] : null;
    }

    /**
     * Alle zum Abrechnungszeitraum gueltigen Kategorien fuer das Auswahlfeld
     * der Pruefoberflaeche.
     *
     * @return list<CostCategory>
     */
    public function selectable(BillingRun $billingRun): array
    {
        $categories = CostCategory::query()
            ->where(function ($query) use ($billingRun): void {
                $query->whereNull('organization_id')
                    ->orWhere('organization_id', $billingRun->getAttribute('organization_id'));
            })
            ->validOn($this->referenceDate($billingRun))
            ->orderBy('sort_order')
            ->get()
            ->all();

        return array_values($categories);
    }

    private function referenceDate(BillingRun $billingRun): string
    {
        $start = $billingRun->getAttribute('period_start');

        return $start instanceof Carbon ? $start->toDateString() : Carbon::now()->toDateString();
    }
}
