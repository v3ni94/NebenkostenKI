<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Period;

use App\Domain\Period\TimeFactor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Zeitanteil einer Kostenzeile: Nutzungstage zu Tagen des
 * Abrechnungszeitraums.
 */
final class TimeFactorTest extends TestCase
{
    #[Test]
    public function angewandter_zeitfaktor_ist_ein_exakter_bruch(): void
    {
        $factor = TimeFactor::applied(184, 365);

        $this->assertSame('184/365', (string) $factor->factor());
        $this->assertSame('184 von 365 Tagen', $factor->explanation());
        $this->assertFalse($factor->includedInAllocationKey);
    }

    #[Test]
    public function volljahr_ergibt_faktor_eins(): void
    {
        $factor = TimeFactor::applied(365, 365);

        $this->assertTrue($factor->factor()->isEqualTo(1));
        $this->assertSame('1,000000', $factor->formattedFactor());
    }

    #[Test]
    public function im_schluessel_enthaltener_zeitanteil_ergibt_faktor_eins(): void
    {
        $factor = TimeFactor::includedInKey(181, 365);

        $this->assertTrue($factor->factor()->isEqualTo(1));
        $this->assertTrue($factor->includedInAllocationKey);
        $this->assertSame(
            '181 von 365 Tagen (Zeitanteil im Verteilerschlüssel enthalten)',
            $factor->explanation()
        );
    }

    #[Test]
    public function schaltjahr_wird_taggenau_abgebildet(): void
    {
        $factor = TimeFactor::applied(182, 366);

        $this->assertSame('182/366', (string) $factor->factor());
        $this->assertSame('0,497268', $factor->formattedFactor());
    }
}
