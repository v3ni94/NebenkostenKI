<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Application\Payment\CalculatePrice;
use App\Application\Payment\Dto\VatDecomposition;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Preisberechnung und Zerlegung in Netto, Umsatzsteuer und Brutto
 * (Abschnitt 1.3, ADR-010).
 *
 * Verbindlich: Netto plus Steuer ergibt exakt den Bruttobetrag. Die
 * Rundungsdifferenz liegt in der Steuer.
 */
final class PriceCalculationTest extends TestCase
{
    /**
     * @return list<array{int, int, int, int}>
     */
    public static function mengen(): array
    {
        // Anzahl, Brutto, Netto, Steuer bei 24,90 EUR brutto und 19 Prozent.
        // Netto ist Anzahl mal 20,92 EUR, die Steuer die Differenz zum Brutto.
        return [
            [1, 2490, 2092, 398],
            [2, 4980, 4184, 796],
            [7, 17430, 14644, 2786],
            [13, 32370, 27196, 5174],
        ];
    }

    #[DataProvider('mengen')]
    public function test_preis_wird_je_erzeugter_abrechnung_gebildet(
        int $anzahl,
        int $brutto,
        int $netto,
        int $steuer,
    ): void {
        $preis = app(CalculatePrice::class)->estimate($anzahl);

        self::assertSame($anzahl, $preis->statementCount);
        self::assertSame(2490, $preis->unitGrossCent);
        self::assertSame($brutto, $preis->grossCent);
        self::assertSame($netto, $preis->netCent);
        self::assertSame($steuer, $preis->taxCent);
        self::assertSame('19', $preis->vatRatePercent);
        self::assertSame('eur', $preis->currency);
    }

    #[DataProvider('mengen')]
    public function test_netto_plus_steuer_ergibt_exakt_brutto(
        int $anzahl,
        int $brutto,
        int $netto,
        int $steuer,
    ): void {
        $preis = app(CalculatePrice::class)->estimate($anzahl);

        self::assertSame($brutto, $preis->netCent + $preis->taxCent);
        self::assertSame($netto + $steuer, $preis->grossCent);
        self::assertTrue($preis->isConsistent());
    }

    public function test_zerlegung_rundet_kaufmaennisch_und_legt_die_differenz_in_die_steuer(): void
    {
        // 24,90 EUR brutto: 2490 / 1,19 = 2092,4369..., kaufmaennisch 2092.
        $zerlegung = VatDecomposition::fromGross(2490, '19');

        self::assertSame(2092, $zerlegung->netCent);
        self::assertSame(398, $zerlegung->taxCent);
        self::assertSame(2490, $zerlegung->netCent + $zerlegung->taxCent);

        // 1 Cent brutto: das Netto rundet auf 1 Cent, die Steuer bleibt 0.
        $klein = VatDecomposition::fromGross(1, '19');

        self::assertSame(1, $klein->netCent);
        self::assertSame(0, $klein->taxCent);
    }

    public function test_zerlegung_ist_fuer_jede_menge_bis_hundert_stimmig(): void
    {
        for ($anzahl = 1; $anzahl <= 100; $anzahl++) {
            $preis = app(CalculatePrice::class)->estimate($anzahl);

            self::assertSame(
                $preis->grossCent,
                $preis->netCent + $preis->taxCent,
                sprintf('Bei %d Abrechnungen geht die Zerlegung nicht auf.', $anzahl),
            );
            self::assertSame($anzahl * 2490, $preis->grossCent);
        }
    }

    public function test_grundpreis_wird_getrennt_ausgewiesen(): void
    {
        config()->set('smartabrechnen.pricing.base_gross_cent', 1000);

        $preis = app(CalculatePrice::class)->estimate(2);

        self::assertSame(1000, $preis->baseGrossCent);
        self::assertSame(2 * 2490 + 1000, $preis->grossCent);
        self::assertSame($preis->grossCent, $preis->netCent + $preis->taxCent);
        self::assertSame(840, $preis->baseNetCent());
        self::assertTrue($preis->hasBaseAmount());
    }

    public function test_ohne_abrechnung_bleibt_der_preis_null(): void
    {
        $preis = app(CalculatePrice::class)->estimate(0);

        self::assertSame(0, $preis->grossCent);
        self::assertSame(0, $preis->netCent);
        self::assertSame(0, $preis->taxCent);
    }

    public function test_steuersatz_null_ergibt_netto_gleich_brutto(): void
    {
        $zerlegung = VatDecomposition::fromGross(2490, '0');

        self::assertSame(2490, $zerlegung->netCent);
        self::assertSame(0, $zerlegung->taxCent);
    }

    public function test_nettoeinzelpreis_der_rechnungsposition_ist_ganzzahlig(): void
    {
        $preis = app(CalculatePrice::class)->estimate(7);

        self::assertSame(2092, $preis->unitNetCent());
    }

    public function test_anzahl_mal_nettoeinzelpreis_ergibt_den_positionsnettobetrag(): void
    {
        foreach ([3, 7, 13] as $anzahl) {
            $preis = app(CalculatePrice::class)->estimate($anzahl);

            self::assertSame($anzahl * $preis->unitNetCent(), $preis->netCent - $preis->baseNetCent());
            self::assertSame($preis->grossCent, $preis->netCent + $preis->taxCent);
            self::assertTrue($preis->isConsistent());
        }
    }
}
