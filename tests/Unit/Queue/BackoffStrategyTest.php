<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use App\Services\Queue\BackoffStrategy;
use Tests\TestCase;

/**
 * Prueft den exponentiellen Backoff (ADR-006).
 */
class BackoffStrategyTest extends TestCase
{
    public function test_verzoegerung_waechst_exponentiell(): void
    {
        $backoff = new BackoffStrategy(30, 2, 3600);

        $this->assertSame(30, $backoff->delayFor(1));
        $this->assertSame(60, $backoff->delayFor(2));
        $this->assertSame(120, $backoff->delayFor(3));
        $this->assertSame(240, $backoff->delayFor(4));
    }

    public function test_verzoegerung_ist_nach_oben_gedeckelt(): void
    {
        $backoff = new BackoffStrategy(30, 2, 300);

        $this->assertSame(240, $backoff->delayFor(4));
        $this->assertSame(300, $backoff->delayFor(5));
        $this->assertSame(300, $backoff->delayFor(40));
    }

    public function test_erster_versuch_wird_wie_versuch_eins_behandelt(): void
    {
        $backoff = new BackoffStrategy(30, 2, 3600);

        $this->assertSame(30, $backoff->delayFor(0));
        $this->assertSame(30, $backoff->delayFor(-5));
    }

    public function test_jitter_bleibt_innerhalb_der_grenzen(): void
    {
        $backoff = new BackoffStrategy(30, 2, 300, 10);

        for ($i = 0; $i < 20; $i++) {
            $delay = $backoff->delayFor(1);

            $this->assertGreaterThanOrEqual(30, $delay);
            $this->assertLessThanOrEqual(40, $delay);
        }
    }
}
