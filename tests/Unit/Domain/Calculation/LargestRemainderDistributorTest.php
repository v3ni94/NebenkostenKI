<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Calculation;

use App\Domain\Calculation\Rounding\InvalidDistributionException;
use App\Domain\Calculation\Rounding\LargestRemainderDistributor;
use Brick\Math\BigRational;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Rundung auf Cent erst am Ende einer Kostenzeile, Verteilung der
 * Rundungsdifferenz mit dem Largest-Remainder-Verfahren
 * (Pflichtenheft Abschnitt 11.3).
 */
final class LargestRemainderDistributorTest extends TestCase
{
    private LargestRemainderDistributor $distributor;

    protected function setUp(): void
    {
        $this->distributor = new LargestRemainderDistributor;
    }

    #[Test]
    public function rundungsdifferenz_ueber_drei_einheiten_wird_exakt_verteilt(): void
    {
        // 100,00 EUR zu je einem Drittel: exakt 3.333,33 Cent je Einheit,
        // abgerundet 3.333 Cent, ein Cent verbleibt und geht an den ersten
        // Schlüssel bei Restgleichstand.
        $result = $this->distributor->distribute(10000, [
            'W-1' => BigRational::nd(1, 3),
            'W-2' => BigRational::nd(1, 3),
            'W-3' => BigRational::nd(1, 3),
        ]);

        $this->assertSame(3334, $result->amountFor('W-1'));
        $this->assertSame(3333, $result->amountFor('W-2'));
        $this->assertSame(3333, $result->amountFor('W-3'));
        $this->assertSame(10000, $result->distributedTotal());
        $this->assertTrue($result->isExact());
    }

    #[Test]
    public function rundungsdifferenz_ueber_sieben_einheiten_wird_exakt_verteilt(): void
    {
        // 1.000,00 EUR zu je einem Siebtel: exakt 14.285,714... Cent,
        // abgerundet 14.285 Cent, fünf Cent verbleiben.
        $weights = [];

        foreach (['E-1', 'E-2', 'E-3', 'E-4', 'E-5', 'E-6', 'E-7'] as $key) {
            $weights[$key] = BigRational::nd(1, 7);
        }

        $result = $this->distributor->distribute(100000, $weights);

        $this->assertSame(100000, $result->distributedTotal());
        $this->assertSame(14286, $result->amountFor('E-1'));
        $this->assertSame(14286, $result->amountFor('E-2'));
        $this->assertSame(14286, $result->amountFor('E-3'));
        $this->assertSame(14286, $result->amountFor('E-4'));
        $this->assertSame(14286, $result->amountFor('E-5'));
        $this->assertSame(14285, $result->amountFor('E-6'));
        $this->assertSame(14285, $result->amountFor('E-7'));
    }

    #[Test]
    public function bei_gleichstand_entscheidet_der_beteiligtenschluessel_aufsteigend(): void
    {
        $result = $this->distributor->distribute(10000, [
            'W-3' => BigRational::nd(1, 3),
            'W-1' => BigRational::nd(1, 3),
            'W-2' => BigRational::nd(1, 3),
        ]);

        // Reihenfolge der Eingabe ist unerheblich, der Cent geht an W-1.
        $this->assertSame(3334, $result->amountFor('W-1'));
        $this->assertSame(3333, $result->amountFor('W-2'));
        $this->assertSame(3333, $result->amountFor('W-3'));
    }

    #[Test]
    public function ungleiche_gewichte_werden_nach_groesstem_rest_aufgefuellt(): void
    {
        // 385,00 EUR nach Wohnfläche 45,00 / 62,50 / 78,00 m² von 185,50 m².
        // exakt: 9.339,6226 / 12.971,6981 / 16.188,6792 Cent
        // abgerundet 9.339 + 12.971 + 16.188 = 38.498, zwei Cent verbleiben.
        // Größte Reste: W-2 (0,6981) und W-3 (0,6792) vor W-1 (0,6226).
        $result = $this->distributor->distribute(38500, [
            'W-1' => BigRational::nd(4500, 18550),
            'W-2' => BigRational::nd(6250, 18550),
            'W-3' => BigRational::nd(7800, 18550),
        ]);

        $this->assertSame(38500, $result->distributedTotal());
        $this->assertSame(9339, $result->amountFor('W-1'));
        $this->assertSame(12972, $result->amountFor('W-2'));
        $this->assertSame(16189, $result->amountFor('W-3'));
    }

    #[Test]
    public function negative_gutschrift_wird_exakt_verteilt(): void
    {
        // Gutschrift von 181,00 EUR nach 62,50 / 37,50 m²:
        // exakt -11.312,50 und -6.787,50 Cent. Beide Reste sind gleich,
        // deshalb erhält der alphabetisch erste Schlüssel den Cent.
        $result = $this->distributor->distribute(-18100, [
            'mv-a' => BigRational::nd(625, 1000),
            'mv-b' => BigRational::nd(375, 1000),
        ]);

        $this->assertSame(-11312, $result->amountFor('mv-a'));
        $this->assertSame(-6788, $result->amountFor('mv-b'));
        $this->assertSame(-18100, $result->distributedTotal());
        $this->assertTrue($result->isExact());
    }

    #[Test]
    public function rundungsausgleich_wird_je_beteiligtem_ausgewiesen(): void
    {
        $result = $this->distributor->distribute(10000, [
            'W-1' => BigRational::nd(1, 3),
            'W-2' => BigRational::nd(1, 3),
            'W-3' => BigRational::nd(1, 3),
        ]);

        // Kaufmännisch gerundet ergäbe jede Einheit 3.333 Cent; W-1 erhält
        // über das Largest-Remainder-Verfahren einen Cent mehr.
        $this->assertSame(1, $result->adjustmentFor('W-1'));
        $this->assertSame(0, $result->adjustmentFor('W-2'));
        $this->assertSame(0, $result->adjustmentFor('W-3'));
    }

    #[Test]
    public function exakt_teilbare_betraege_erzeugen_keinen_ausgleich(): void
    {
        $result = $this->distributor->distribute(198000, [
            'W-1' => BigRational::nd(1, 6),
            'W-2' => BigRational::nd(1, 6),
            'W-3' => BigRational::nd(1, 6),
            'W-4' => BigRational::nd(1, 6),
            'W-5' => BigRational::nd(1, 6),
            'W-6' => BigRational::nd(1, 6),
        ]);

        foreach (['W-1', 'W-2', 'W-3', 'W-4', 'W-5', 'W-6'] as $key) {
            $this->assertSame(33000, $result->amountFor($key));
            $this->assertSame(0, $result->adjustmentFor($key));
        }
    }

    #[Test]
    public function gewicht_null_erhaelt_keinen_anteil(): void
    {
        $result = $this->distributor->distribute(184720, [
            'mv-1' => BigRational::nd(730, 4618),
            'mv-2' => BigRational::nd(3888, 4618),
            'leerstand' => BigRational::nd(0, 4618),
        ]);

        $this->assertSame(0, $result->amountFor('leerstand'));
        $this->assertSame(184720, $result->distributedTotal());
    }

    #[Test]
    public function summe_der_gewichte_muss_exakt_eins_sein(): void
    {
        $this->expectException(InvalidDistributionException::class);
        $this->expectExceptionMessage('exakt 1');

        $this->distributor->distribute(10000, [
            'W-1' => BigRational::nd(1, 2),
            'W-2' => BigRational::nd(1, 4),
        ]);
    }

    #[Test]
    public function negative_gewichte_sind_unzulaessig(): void
    {
        $this->expectException(InvalidDistributionException::class);

        $this->distributor->distribute(10000, [
            'W-1' => BigRational::nd(3, 2),
            'W-2' => BigRational::nd(-1, 2),
        ]);
    }

    #[Test]
    public function verteilung_ohne_gewichte_ist_unzulaessig(): void
    {
        $this->expectException(InvalidDistributionException::class);

        $this->distributor->distribute(10000, []);
    }

    #[Test]
    public function proportionale_verteilung_normiert_die_gewichte(): void
    {
        // Taggenaue Ersatzverteilung eines Verbrauchs: 181 zu 184 Tage.
        $result = $this->distributor->distributeProportionally(100000, [
            'mv-1' => BigRational::nd(181, 1),
            'mv-2' => BigRational::nd(184, 1),
        ]);

        $this->assertSame(100000, $result->distributedTotal());
        $this->assertSame(49589, $result->amountFor('mv-1'));
        $this->assertSame(50411, $result->amountFor('mv-2'));
    }

    #[Test]
    public function exakte_anteile_bleiben_als_bruch_nachvollziehbar(): void
    {
        $result = $this->distributor->distribute(10000, [
            'W-1' => BigRational::nd(1, 3),
            'W-2' => BigRational::nd(2, 3),
        ]);

        $this->assertTrue($result->exactFor('W-1')->isEqualTo(BigRational::nd(10000, 3)));
        $this->assertSame(['W-1', 'W-2'], $result->participantKeys());
    }
}
