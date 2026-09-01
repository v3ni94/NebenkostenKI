<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Heating;

use App\Domain\Calculation\Heating\ManualHeatingEntry;
use App\Domain\Calculation\Heating\ManualHeatingInput;
use App\Domain\Calculation\Heating\ManualHeatingReconciler;
use App\Domain\Calculation\Result\CheckCode;
use App\Domain\Calculation\Rounding\LargestRemainderDistributor;
use App\Domain\Money\Money;
use App\Domain\Period\DatePeriodRange;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fall B: manuell erfasste Heizkosten.
 *
 * Geprueft werden die Pruefsumme innerhalb und ausserhalb der Toleranz, der
 * Verzicht auf die Gegenprobe ohne Gesamtbetrag, die Direktzuordnung je
 * Einheit und die zeitanteilige Verteilung bei Mieterwechsel.
 */
final class ManualHeatingReconcilerTest extends TestCase
{
    private ManualHeatingReconciler $reconciler;

    protected function setUp(): void
    {
        $this->reconciler = new ManualHeatingReconciler(new LargestRemainderDistributor);
    }

    #[Test]
    public function die_summe_der_erfassten_betraege_wird_exakt_gebildet(): void
    {
        $result = $this->reconciler->reconcile($this->input(Money::fromEuros('1500.00')), Money::fromCents(100));

        // 800,00 + 200,00 + 40,00 + 60,00 + 100,00 = 1.200,00
        // 240,00 + 60,00 + 0,00 + 0,00 + 0,00      =   300,00
        self::assertSame(150000, $result->sumOfRecordedAmounts->cents);
        self::assertSame('1.500,00 EUR', $result->sumOfRecordedAmounts->format());
    }

    #[Test]
    public function der_co2_anteil_des_vermieters_wird_nicht_auf_mieter_verteilt(): void
    {
        $result = $this->reconciler->reconcile($this->input(Money::fromEuros('1500.00')), Money::fromCents(100));

        self::assertSame(146000, $result->sumOfTenantAmounts->cents);
    }

    #[Test]
    public function eine_pruefsumme_innerhalb_der_toleranz_blockiert_nicht(): void
    {
        $result = $this->reconciler->reconcile($this->input(Money::fromEuros('1499.50')), Money::fromCents(100));

        self::assertTrue($result->checksumAvailable);
        self::assertTrue($result->withinTolerance);
        self::assertFalse($result->blocksFinalization());
        self::assertSame(50, $result->difference?->cents);
        self::assertSame(CheckCode::HEATING_CHECKSUM_WITHIN_TOLERANCE, $result->findings[0]->code);
    }

    #[Test]
    public function eine_pruefsumme_ausserhalb_der_toleranz_blockiert_die_finalisierung(): void
    {
        $result = $this->reconciler->reconcile($this->input(Money::fromEuros('1400.00')), Money::fromCents(100));

        self::assertFalse($result->withinTolerance);
        self::assertTrue($result->blocksFinalization());
        self::assertSame(10000, $result->difference?->cents);
        self::assertSame(CheckCode::HEATING_CHECKSUM_OUT_OF_TOLERANCE, $result->findings[0]->code);
        self::assertStringContainsString('Toleranz', $result->findings[0]->message);
        self::assertNull($result->allocationKey(), 'Bei blockierender Abweichung wird nichts zugeordnet.');
    }

    #[Test]
    public function ohne_gesamtbetrag_entfaellt_die_pruefsumme_und_es_erscheint_ein_hinweis(): void
    {
        $result = $this->reconciler->reconcile($this->input(null), Money::fromCents(100));

        self::assertFalse($result->checksumAvailable);
        self::assertNull($result->difference);
        self::assertSame([], $result->findings);
        self::assertFalse($result->blocksFinalization());
        self::assertNotNull($result->hint);
        self::assertStringContainsString('keine Gegenprobe', (string) $result->hint);
    }

    #[Test]
    public function die_uebernahme_erfolgt_als_direktzuordnung_je_einheit(): void
    {
        $result = $this->reconciler->reconcile($this->input(Money::fromEuros('1500.00')), Money::fromCents(100));
        $key = $result->allocationKey();

        self::assertNotNull($key);
        self::assertSame(116000, $key->amountFor('W-1')->cents);
        self::assertSame(30000, $key->amountFor('W-2')->cents);
        self::assertSame(146000, $key->totalAmount()->cents);
        self::assertStringContainsString('Direktzuordnung', $key->explanationFor('W-1'));
    }

    #[Test]
    public function ohne_erfasste_betraege_entsteht_kein_verteilerschluessel(): void
    {
        $input = new ManualHeatingInput(
            DatePeriodRange::calendarYear(2025),
            ['W-1' => $this->entry('W-1', '0.00', '0.00', '0.00', '0.00', '0.00')],
        );

        self::assertNull($this->reconciler->allocationKey($input));
        self::assertSame(['W-1'], $input->unitsWithoutAmounts());
    }

    #[Test]
    public function bei_mieterwechsel_wird_der_betrag_zeitanteilig_nach_nutzungstagen_verteilt(): void
    {
        $shares = $this->reconciler->splitByUsageDays(Money::fromEuros('1200.00'), [
            'mv-1' => 182,
            'mv-2' => 183,
        ]);

        self::assertSame(59836, $shares['mv-1']->cents);
        self::assertSame(60164, $shares['mv-2']->cents);
        self::assertSame(120000, $shares['mv-1']->plus($shares['mv-2'])->cents);
    }

    #[Test]
    public function ohne_mieterwechsel_bleibt_der_betrag_unveraendert(): void
    {
        $shares = $this->reconciler->splitByUsageDays(Money::fromEuros('1234.56'), ['mv-1' => 365]);

        self::assertSame(123456, $shares['mv-1']->cents);
    }

    #[Test]
    public function ohne_nutzungstage_entsteht_keine_verteilung(): void
    {
        self::assertSame([], $this->reconciler->splitByUsageDays(Money::fromEuros('100.00'), []));
        self::assertSame([], $this->reconciler->splitByUsageDays(Money::fromEuros('100.00'), ['mv-1' => 0]));
    }

    #[Test]
    public function die_zeitanteilige_verteilung_bleibt_auf_den_cent_genau(): void
    {
        $shares = $this->reconciler->splitByUsageDays(Money::fromEuros('1000.01'), [
            'mv-1' => 100,
            'mv-2' => 100,
            'mv-3' => 165,
        ]);

        $summe = $shares['mv-1']->plus($shares['mv-2'])->plus($shares['mv-3']);

        self::assertSame(100001, $summe->cents);
    }

    private function input(?Money $declaredTotal): ManualHeatingInput
    {
        return new ManualHeatingInput(
            DatePeriodRange::calendarYear(2025),
            [
                'W-1' => $this->entry('W-1', '800.00', '200.00', '40.00', '60.00', '100.00'),
                'W-2' => $this->entry('W-2', '240.00', '60.00', '0.00', '0.00', '0.00'),
            ],
            $declaredTotal,
            'Eigene Tabellenkalkulation vom 15.03.2026',
        );
    }

    private function entry(
        string $unitKey,
        string $heating,
        string $warmWater,
        string $co2Landlord,
        string $co2Tenant,
        string $other,
    ): ManualHeatingEntry {
        return new ManualHeatingEntry(
            $unitKey,
            'Einheit '.$unitKey,
            Money::fromEuros($heating),
            Money::fromEuros($warmWater),
            Money::fromEuros($co2Landlord),
            Money::fromEuros($co2Tenant),
            Money::fromEuros($other),
        );
    }
}
