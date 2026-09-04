<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Allocation;

use App\Domain\Allocation\ConsumptionKeyBuilder;
use App\Domain\Allocation\ConsumptionRecord;
use App\Domain\Allocation\MissingInterimReadingException;
use App\Domain\Allocation\ReadingsExceedUnitTotalException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verbrauch bei Nutzerwechsel: Teilung nur bei vorhandener Zwischenablesung,
 * sonst keine stille Schätzung (Pflichtenheft Abschnitt 11.2).
 */
final class ConsumptionKeyBuilderTest extends TestCase
{
    private ConsumptionKeyBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ConsumptionKeyBuilder;
    }

    #[Test]
    public function zwischenablesung_teilt_den_verbrauch_exakt(): void
    {
        $key = $this->builder->build(
            [
                ConsumptionRecord::forOccupancy('W-2', 'mv-2', '61.000'),
                ConsumptionRecord::forOccupancy('W-2', 'mv-3', '39.000'),
            ],
            ['W-2' => ['mv-2' => 181, 'mv-3' => 184]],
            'm³'
        );

        $this->assertSame('61.000', (string) $key->numeratorFor('mv-2'));
        $this->assertSame('39.000', (string) $key->numeratorFor('mv-3'));
        $this->assertSame('100.000', (string) $key->denominator());
        $this->assertFalse($key->usesSubstituteDistributionFor('mv-2'));
    }

    #[Test]
    public function fehlende_zwischenablesung_loest_eine_domain_exception_aus(): void
    {
        $this->expectException(MissingInterimReadingException::class);
        $this->expectExceptionMessage('keine Zwischenablesung');

        $this->builder->build(
            [ConsumptionRecord::forUnit('W-2', '100.000')],
            ['W-2' => ['mv-2' => 181, 'mv-3' => 184]],
            'm³'
        );
    }

    #[Test]
    public function unvollstaendige_zwischenablesung_loest_eine_domain_exception_aus(): void
    {
        $this->expectException(MissingInterimReadingException::class);

        $this->builder->build(
            [ConsumptionRecord::forOccupancy('W-2', 'mv-2', '61.000')],
            ['W-2' => ['mv-2' => 181, 'mv-3' => 184]],
            'm³'
        );
    }

    #[Test]
    public function bestaetigte_ersatzverteilung_teilt_taggenau_und_kennzeichnet_die_zeilen(): void
    {
        // 100,000 m³ auf 181 und 184 Tage: exakt 49,589041 und 50,410959 m³.
        // Auf drei Dezimalstellen mit Largest Remainder: 49,589 und 50,411.
        $key = $this->builder->build(
            [ConsumptionRecord::forUnit('W-2', '100.000')],
            ['W-2' => ['mv-2' => 181, 'mv-3' => 184]],
            'm³',
            ['W-2']
        );

        $this->assertSame('49.589', (string) $key->numeratorFor('mv-2'));
        $this->assertSame('50.411', (string) $key->numeratorFor('mv-3'));
        $this->assertSame('100.000', (string) $key->denominator());
        $this->assertTrue($key->usesSubstituteDistributionFor('mv-2'));
        $this->assertTrue($key->usesSubstituteDistributionFor('mv-3'));
    }

    #[Test]
    public function unvollstaendige_zwischenablesung_wird_mit_bestaetigung_aus_dem_jahreswert_ergaenzt(): void
    {
        // Jahreswert 120,000 m³, nur mv-2 hat eine Ablesung (61,000). Der
        // Rest 59,000 geht taggenau an mv-3 und wird gekennzeichnet; mv-2
        // behaelt seinen abgelesenen Wert ohne Kennzeichnung.
        $key = $this->builder->build(
            [
                ConsumptionRecord::forUnit('W-2', '120.000'),
                ConsumptionRecord::forOccupancy('W-2', 'mv-2', '61.000'),
            ],
            ['W-2' => ['mv-2' => 181, 'mv-3' => 184]],
            'm³',
            ['W-2']
        );

        $this->assertSame('61.000', (string) $key->numeratorFor('mv-2'));
        $this->assertSame('59.000', (string) $key->numeratorFor('mv-3'));
        $this->assertSame('120.000', (string) $key->denominator());
        $this->assertFalse($key->usesSubstituteDistributionFor('mv-2'));
        $this->assertTrue($key->usesSubstituteDistributionFor('mv-3'));
    }

    /**
     * Befund R1: Uebersteigt die Summe der Ablesungen den Jahreswert der
     * Einheit, wird der Rest nicht still auf null gesetzt.
     */
    #[Test]
    public function ablesungen_ueber_dem_jahreswert_loesen_auch_mit_bestaetigung_eine_domain_exception_aus(): void
    {
        $this->expectException(ReadingsExceedUnitTotalException::class);
        $this->expectExceptionMessage('ergeben zusammen 130.000');

        $this->builder->build(
            [
                ConsumptionRecord::forUnit('W-2', '120.000'),
                ConsumptionRecord::forOccupancy('W-2', 'mv-2', '130.000'),
            ],
            ['W-2' => ['mv-2' => 181, 'mv-3' => 184]],
            'm³',
            ['W-2']
        );
    }

    #[Test]
    public function unvollstaendige_zwischenablesung_ohne_jahreswert_bleibt_auch_mit_bestaetigung_ein_fehler(): void
    {
        $this->expectException(MissingInterimReadingException::class);

        $this->builder->build(
            [ConsumptionRecord::forOccupancy('W-2', 'mv-2', '61.000')],
            ['W-2' => ['mv-2' => 181, 'mv-3' => 184]],
            'm³',
            ['W-2']
        );
    }

    #[Test]
    public function einheit_ohne_nutzerwechsel_braucht_keine_zwischenablesung(): void
    {
        $key = $this->builder->build(
            [
                ConsumptionRecord::forUnit('W-1', '82.000'),
                ConsumptionRecord::forUnit('W-3', '74.000'),
            ],
            [
                'W-1' => ['mv-1' => 365],
                'W-3' => ['mv-4' => 365],
            ],
            'm³'
        );

        $this->assertSame('82.000', (string) $key->numeratorFor('mv-1'));
        $this->assertSame('74.000', (string) $key->numeratorFor('mv-4'));
        $this->assertSame('156.000', (string) $key->denominator());
    }

    #[Test]
    public function leerstand_erhaelt_seinen_abgelesenen_verbrauch(): void
    {
        $key = $this->builder->build(
            [
                ConsumptionRecord::forOccupancy('W-4', 'mv-5', '120.000'),
                ConsumptionRecord::forOccupancy('W-4', 'W-4#leerstand-1', '3.000'),
            ],
            ['W-4' => ['mv-5' => 334, 'W-4#leerstand-1' => 31]],
            'm³'
        );

        $this->assertSame('3.000', (string) $key->numeratorFor('W-4#leerstand-1'));
        $this->assertSame('123.000', (string) $key->denominator());
    }
}
