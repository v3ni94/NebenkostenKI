<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Models\CalculationSnapshot;

/**
 * Preis je Mieterabrechnung und Umsatzsteuersatz (Masterprompt 1.3, 20).
 *
 * ENTSCHEIDUNG ZUR PERSISTENZ
 *
 * Es gibt im Datenmodell keine Preistabelle, und dieses Arbeitspaket darf keine
 * Migration anlegen. Der Adminbereich zeigt deshalb den geltenden Preis an und
 * validiert eine Aenderung gegen den zulaessigen Korridor aus
 * config('smartabrechnen.pricing.admin_range_gross_cent'), schreibt sie aber
 * NICHT dauerhaft. Der geltende Preis bleibt PRICE_PER_STATEMENT_GROSS_CENT in
 * der Serverumgebung. Eine dauerhafte Adminpflege braucht eine eigene Tabelle
 * mit Gueltigkeitszeitraum, siehe Uebergabebericht.
 *
 * VERBINDLICH (Masterprompt 20)
 *
 * Eine Adminaenderung an Preis, Regel oder Prompt wirkt ausschliesslich auf
 * NEUE Berechnungsstaende. Ein bestehender Calculation Snapshot bleibt
 * unveraendert und reproduzierbar: Eingabe, Ergebnis, Regelstand, Domainversion
 * und Hash werden nicht angetastet. Diese Klasse schreibt deshalb niemals in
 * calculation_snapshots und niemals in billing_runs.
 */
final class PricingSettings
{
    /**
     * Geltender Zustand fuer die Anzeige.
     *
     * @return array{
     *     preis_je_abrechnung_cent: int,
     *     grundpreis_cent: int,
     *     steuersatz_prozent: int,
     *     korridor_min_cent: int,
     *     korridor_max_cent: int,
     *     korrekturfrist_tage: int,
     *     im_korridor: bool,
     *     persistenz: string
     * }
     */
    public function state(): array
    {
        $price = $this->configInt('smartabrechnen.pricing.per_statement_gross_cent', 2490);
        $range = $this->range();

        return [
            'preis_je_abrechnung_cent' => $price,
            'grundpreis_cent' => $this->configInt('smartabrechnen.pricing.base_gross_cent', 0),
            'steuersatz_prozent' => $this->configInt('smartabrechnen.pricing.vat_rate_percent', 19),
            'korridor_min_cent' => $range['min'],
            'korridor_max_cent' => $range['max'],
            'korrekturfrist_tage' => $this->configInt('smartabrechnen.pricing.correction_free_days', 0),
            'im_korridor' => $price >= $range['min'] && $price <= $range['max'],
            'persistenz' => 'Der Preis wird ausschließlich über die Serverumgebung gesetzt '
                .'(PRICE_PER_STATEMENT_GROSS_CENT). Der Adminbereich prüft eine geplante Änderung, speichert '
                .'sie aber nicht.',
        ];
    }

    /**
     * @return array{min: int, max: int}
     */
    public function range(): array
    {
        return [
            'min' => $this->configInt('smartabrechnen.pricing.admin_range_gross_cent.min', 2000),
            'max' => $this->configInt('smartabrechnen.pricing.admin_range_gross_cent.max', 3000),
        ];
    }

    public function isWithinRange(int $grossCent): bool
    {
        $range = $this->range();

        return $grossCent >= $range['min'] && $grossCent <= $range['max'];
    }

    /**
     * Anzahl der Berechnungsstaende, die von einer Preisaenderung bewusst NICHT
     * beruehrt werden.
     */
    public function protectedSnapshotCount(): int
    {
        return CalculationSnapshot::query()->count();
    }

    /**
     * Wirkungshinweis einer geprueften Aenderung, fuer die Anzeige.
     */
    public function effectNote(int $grossCent): string
    {
        return sprintf(
            'Ein Preis von %s liegt im zulässigen Korridor. Die Änderung würde ausschließlich für neue '
            .'Berechnungsstände gelten. Die %d vorhandenen Berechnungsstände bleiben unverändert und '
            .'reproduzierbar. Zur Übernahme ist PRICE_PER_STATEMENT_GROSS_CENT in der Serverumgebung zu '
            .'setzen; der Adminbereich speichert den Wert nicht.',
            self::formatCent($grossCent),
            $this->protectedSnapshotCount(),
        );
    }

    public static function formatCent(int $cent): string
    {
        return number_format($cent / 100, 2, ',', '.').' EUR';
    }

    private function configInt(string $key, int $default): int
    {
        $value = config($key);

        return is_numeric($value) ? (int) $value : $default;
    }
}
