<?php

declare(strict_types=1);

namespace Tests\Feature\Calculation;

use App\Application\Calculation\CalculateBillingRun;
use App\Application\Calculation\RecalculateFromSnapshot;
use App\Application\Calculation\SnapshotSerializer;
use App\Models\CostItem;
use App\Models\Unit;

/**
 * Reproduzierbarkeit eines gespeicherten Berechnungsstands
 * (ARCHITECTURE.md Abschnitt 6).
 */
final class RecalculateFromSnapshotTest extends CalculationTestCase
{
    public function test_erneute_berechnung_aus_dem_snapshot_liefert_feldweise_gleiche_werte(): void
    {
        $szenario = $this->szenario();

        $snapshot = app(CalculateBillingRun::class)
            ->handle($szenario['billingRun'], $szenario['user'])
            ->snapshot
            ->refresh();

        $erneut = app(RecalculateFromSnapshot::class)->reproducedResultPayload($snapshot);

        self::assertSame($snapshot->result, $erneut);
    }

    public function test_die_normalisierte_nutzlast_ist_bitgenau_gleich(): void
    {
        $szenario = $this->szenario();

        $snapshot = app(CalculateBillingRun::class)
            ->handle($szenario['billingRun'], $szenario['user'])
            ->snapshot
            ->refresh();

        $serializer = app(SnapshotSerializer::class);
        $erneut = app(RecalculateFromSnapshot::class)->reproducedResultPayload($snapshot);

        self::assertSame(
            $serializer->canonical($snapshot->result),
            $serializer->canonical($erneut)
        );
        self::assertTrue(app(RecalculateFromSnapshot::class)->isReproducible($snapshot));
    }

    public function test_der_snapshot_bleibt_nach_einer_aenderung_der_stammdaten_reproduzierbar(): void
    {
        $szenario = $this->szenario();

        $snapshot = app(CalculateBillingRun::class)
            ->handle($szenario['billingRun'], $szenario['user'])
            ->snapshot
            ->refresh();

        Unit::query()->whereKey($szenario['units'][0]->getKey())->update(['living_area_sqm' => '999.0000']);
        CostItem::query()->whereKey($szenario['costItem']->getKey())->update(['amount_cent' => 1]);

        self::assertTrue(app(RecalculateFromSnapshot::class)->isReproducible($snapshot->refresh()));
    }

    public function test_die_eingabe_wird_verlustfrei_aufgebaut(): void
    {
        $szenario = $this->szenario();

        $snapshot = app(CalculateBillingRun::class)
            ->handle($szenario['billingRun'], $szenario['user'])
            ->snapshot
            ->refresh();

        $eingabe = app(RecalculateFromSnapshot::class)->input($snapshot);
        $serializer = app(SnapshotSerializer::class);

        self::assertSame(
            $serializer->canonical($snapshot->input),
            $serializer->canonical($serializer->input($eingabe))
        );
    }

    public function test_ergebnis_und_snapshot_stimmen_in_summen_und_zeilen_ueberein(): void
    {
        $szenario = $this->szenario();

        $snapshot = app(CalculateBillingRun::class)
            ->handle($szenario['billingRun'], $szenario['user'])
            ->snapshot
            ->refresh();

        $ergebnis = app(RecalculateFromSnapshot::class)->handle($snapshot);

        self::assertSame(2, $ergebnis->statementCount());
        self::assertSame(120000, $ergebnis->ownerOverview->allocatedToTenantsTotal->cents);
        self::assertTrue($ergebnis->ownerOverview->isBalanced());
    }
}
