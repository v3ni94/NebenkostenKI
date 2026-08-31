<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\CostEstimator;
use App\Services\Ai\DailyCostLimiter;
use App\Services\Ai\Exceptions\DailyCostLimitExceededException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Ai\Support\AiTestFactory;

/**
 * Kostenschaetzung und Tagesbudget (Abschnitt 13.8).
 *
 * Die Kalkulationsbasis ist eine dokumentierte Annahme zum Projektstand und
 * regelmaessig gegen die offizielle Preisliste zu pruefen.
 */
final class CostControlTest extends TestCase
{
    public function test_kostenschaetzung_folgt_der_kalkulationsbasis(): void
    {
        $estimator = new CostEstimator([
            'testmodell' => ['input' => 100, 'output' => 500],
        ]);

        // Rechenweg: (1.000.000 * 100 + 200.000 * 500) / 1000 = 200.000 Tausendstel-Cent
        $estimate = $estimator->estimate('testmodell', 1_000_000, 200_000);

        self::assertTrue($estimate->basisAvailable);
        self::assertSame(200_000, $estimate->costMilliCent);
        self::assertSame(200, $estimate->costCent());
        self::assertSame(1_200_000, $estimate->totalTokens());
    }

    public function test_kleine_aufrufe_werden_nicht_auf_null_cent_verkuerzt(): void
    {
        $estimator = new CostEstimator([
            'testmodell' => ['input' => 100, 'output' => 500],
        ]);

        // Rechenweg: 1000 * 100 / 1000 = 100 Tausendstel-Cent, also 0,1 Cent.
        // Ohne die feinere Einheit waere der Aufruf mit 0 oder 1 Cent erfasst
        // und das Tagesbudget dadurch verzerrt.
        $estimate = $estimator->estimate('testmodell', 1000, 0);

        self::assertSame(100, $estimate->costMilliCent);
        self::assertSame(1, $estimate->costCent());

        $groesser = $estimator->estimate('testmodell', 50_000, 5_000);

        self::assertSame(7_500, $groesser->costMilliCent);
        self::assertSame(8, $groesser->costCent(), 'Cent werden aufgerundet, damit das Budget nicht unterlaufen wird.');
    }

    public function test_ohne_kalkulationsbasis_wird_kein_preis_geraten(): void
    {
        $estimator = new CostEstimator([]);
        $estimate = $estimator->estimate('unbekanntes-modell', 100_000, 20_000);

        self::assertFalse($estimate->basisAvailable);
        self::assertNull($estimate->costCent());
        self::assertNull($estimate->costMilliCentOrNull());
    }

    public function test_kalkulationsbasis_kommt_aus_der_konfiguration(): void
    {
        $estimator = AiTestFactory::costEstimator();

        self::assertTrue($estimator->hasBasisFor('claude-haiku-4-5'));
        self::assertTrue($estimator->hasBasisFor('gpt-5.6-luna'));
        self::assertFalse($estimator->hasBasisFor('nicht-konfiguriert'));
    }

    public function test_negative_tokenzahlen_werden_auf_null_gesetzt(): void
    {
        $estimator = new CostEstimator(['testmodell' => ['input' => 100, 'output' => 500]]);
        $estimate = $estimator->estimate('testmodell', -5000, -1000);

        self::assertSame(0, $estimate->costMilliCent);
        self::assertSame(0, $estimate->totalTokens());
    }

    public function test_worst_case_schaetzung_rechnet_mit_voller_ausgabelaenge(): void
    {
        $estimator = new CostEstimator(['testmodell' => ['input' => 100, 'output' => 500]]);

        $worstCase = $estimator->estimateWorstCase('testmodell', 10_000, 16_000);
        $actual = $estimator->estimate('testmodell', 10_000, 900);

        self::assertGreaterThan($actual->costMilliCent, $worstCase->costMilliCent);
    }

    public function test_ohne_konfiguriertes_limit_gilt_kein_tagesbudget(): void
    {
        $limiter = new DailyCostLimiter(null);

        self::assertFalse($limiter->isEnabled());
        self::assertNull($limiter->limitMilliCent());
        self::assertNull($limiter->remainingMilliCent(999_999));
        self::assertFalse($limiter->wouldExceed(999_999_999, 999_999_999));

        $limiter->assertWithinLimit(999_999_999, 999_999_999);
        $this->addToAssertionCount(1);
    }

    public function test_tagesbudget_innerhalb_der_grenze_wird_durchgelassen(): void
    {
        $limiter = new DailyCostLimiter(100);

        self::assertTrue($limiter->isEnabled());
        self::assertSame(100_000, $limiter->limitMilliCent());
        self::assertFalse($limiter->wouldExceed(80_000, 20_000));
        self::assertSame(20_000, $limiter->remainingMilliCent(80_000));

        $limiter->assertWithinLimit(80_000, 20_000);
        $this->addToAssertionCount(1);
    }

    public function test_ueberschreitung_des_tagesbudgets_wirft_ausnahme(): void
    {
        $limiter = new DailyCostLimiter(100);

        self::assertTrue($limiter->wouldExceed(80_000, 20_001));

        $this->expectException(DailyCostLimitExceededException::class);
        $limiter->assertWithinLimit(80_000, 20_001);
    }

    public function test_ausnahme_nennt_nur_betraege_und_keinen_dokumentinhalt(): void
    {
        $limiter = new DailyCostLimiter(50);

        try {
            $limiter->assertWithinLimit(49_000, 5_000);
            self::fail('Es wurde keine Ausnahme geworfen.');
        } catch (DailyCostLimitExceededException $exception) {
            self::assertStringContainsString('Tagesbudget', $exception->getMessage());
            self::assertStringContainsString('50 Cent', $exception->getMessage());
            self::assertStringNotContainsString('Beispielweg', $exception->getMessage());
        }
    }

    public function test_verbleibendes_budget_wird_nicht_negativ(): void
    {
        $limiter = new DailyCostLimiter(10);

        self::assertSame(0, $limiter->remainingMilliCent(50_000));
    }

    public function test_limiter_kann_aus_der_konfiguration_gebaut_werden(): void
    {
        $limiter = DailyCostLimiter::fromConfig(AiTestFactory::config(['max_daily_cost_cent_per_user' => 250]));

        self::assertSame(250, $limiter->limitCent());
        self::assertSame(250_000, $limiter->limitMilliCent());
    }
}
