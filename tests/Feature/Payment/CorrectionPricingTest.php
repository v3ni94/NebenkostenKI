<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Application\Payment\CorrectionPriceRule;
use App\Enums\BillingRunStatus;
use App\Models\BillingRun;

/**
 * Preisregel fuer Korrekturen nach Zahlung (Abschnitt 11.5).
 */
final class CorrectionPricingTest extends PaymentTestCase
{
    private function finalisiert(int $abrechnungen = 2, int $tageSeitAbschluss = 0): BillingRun
    {
        $daten = $this->vorschaubereiterLauf($abrechnungen);

        /** @var BillingRun $lauf */
        $lauf = BillingRun::query()->findOrFail($daten['billingRun']->getKey());

        $lauf->forceFill([
            'status' => BillingRunStatus::FINALIZED,
            'paid_at' => now()->subDays($tageSeitAbschluss),
            'finalized_at' => now()->subDays($tageSeitAbschluss),
        ])->save();

        return $lauf;
    }

    public function test_ohne_konfigurierte_frist_ist_eine_korrektur_nicht_kostenfrei(): void
    {
        $lauf = $this->finalisiert(2);

        $regel = app(CorrectionPriceRule::class);

        self::assertSame(0, $regel->freeDays());

        $ergebnis = $regel->evaluate($lauf);

        self::assertFalse($ergebnis->freeOfCharge);
        self::assertTrue($ergebnis->requiresConfirmation());
        self::assertNotNull($ergebnis->quote);
        self::assertSame(4980, $ergebnis->quote->grossCent);
        self::assertStringContainsString('49,80 EUR', $ergebnis->notice);
        self::assertStringContainsString('ausdrücklichen Bestätigung', $ergebnis->notice);
    }

    public function test_innerhalb_der_administrativ_festgelegten_frist_ist_die_korrektur_kostenfrei(): void
    {
        config()->set('smartabrechnen.pricing.correction_free_days', 14);

        $lauf = $this->finalisiert(2, tageSeitAbschluss: 3);

        $ergebnis = app(CorrectionPriceRule::class)->evaluate($lauf);

        self::assertTrue($ergebnis->freeOfCharge);
        self::assertFalse($ergebnis->requiresConfirmation());
        self::assertNull($ergebnis->quote);
        self::assertSame(14, $ergebnis->freeDays);
        self::assertStringContainsString('kostenfrei', $ergebnis->notice);
    }

    public function test_nach_ablauf_der_frist_entsteht_ein_bestaetigungspflichtiger_betrag(): void
    {
        config()->set('smartabrechnen.pricing.correction_free_days', 14);

        $lauf = $this->finalisiert(3, tageSeitAbschluss: 40);

        $ergebnis = app(CorrectionPriceRule::class)->evaluate($lauf);

        self::assertFalse($ergebnis->freeOfCharge);
        self::assertTrue($ergebnis->requiresConfirmation());
        self::assertNotNull($ergebnis->quote);
        self::assertSame(3, $ergebnis->quote->statementCount);
        self::assertSame(7470, $ergebnis->quote->grossCent);
        self::assertStringContainsString('abgelaufen', $ergebnis->notice);
    }

    public function test_der_betrag_der_korrektur_wird_serverseitig_aus_der_anzahl_gebildet(): void
    {
        config()->set('smartabrechnen.pricing.correction_free_days', 0);

        $lauf = $this->finalisiert(7);

        $ergebnis = app(CorrectionPriceRule::class)->evaluate($lauf);

        self::assertNotNull($ergebnis->quote);
        self::assertSame(7, $ergebnis->quote->statementCount);
        self::assertSame(17430, $ergebnis->quote->grossCent);
        self::assertSame(
            $ergebnis->quote->grossCent,
            $ergebnis->quote->netCent + $ergebnis->quote->taxCent,
        );
    }
}
