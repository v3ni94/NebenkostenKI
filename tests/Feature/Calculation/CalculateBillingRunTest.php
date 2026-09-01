<?php

declare(strict_types=1);

namespace Tests\Feature\Calculation;

use App\Application\Calculation\CalculateBillingRun;
use App\Domain\Support\EngineVersion;
use App\Enums\CalculationSnapshotStatus;
use App\Enums\StatementResultKind;
use App\Enums\UnitStatementStatus;
use App\Models\CalculationSnapshot;
use App\Models\CostItem;
use App\Models\UnitStatement;
use App\Models\UnitStatementLine;
use App\Models\ValidationIssue;
use Illuminate\Support\Carbon;

/**
 * Berechnung eines Abrechnungslaufs und Unveränderlichkeit des
 * Berechnungsstands.
 */
final class CalculateBillingRunTest extends CalculationTestCase
{
    public function test_snapshot_enthaelt_eingabe_ergebnis_versionen_und_hash(): void
    {
        $szenario = $this->szenario();

        $ergebnis = app(CalculateBillingRun::class)->handle($szenario['billingRun'], $szenario['user']);
        $snapshot = $ergebnis->snapshot->refresh();

        self::assertNotEmpty($snapshot->input);
        self::assertNotEmpty($snapshot->result);
        self::assertSame(EngineVersion::CURRENT, $snapshot->domain_version);
        self::assertNotSame('', $snapshot->ruleset_version);
        self::assertSame(64, strlen($snapshot->hash));
        self::assertSame(1, $snapshot->version_number);
        self::assertSame(CalculationSnapshotStatus::BERECHNET, $snapshot->status);
        self::assertArrayHasKey('units', $snapshot->input);
        self::assertArrayHasKey('allocationKeys', $snapshot->input);
        self::assertArrayHasKey('statements', $snapshot->result);
    }

    public function test_mieterabrechnungen_und_rechenzeilen_werden_geschrieben(): void
    {
        $szenario = $this->szenario();

        app(CalculateBillingRun::class)->handle($szenario['billingRun'], $szenario['user']);

        $abrechnungen = UnitStatement::query()
            ->where('billing_run_id', $szenario['billingRun']->getKey())
            ->get();

        self::assertCount(2, $abrechnungen);

        foreach ($abrechnungen as $abrechnung) {
            self::assertSame(365, $abrechnung->period_days);
            self::assertSame(365, $abrechnung->days_used);
            self::assertSame(UnitStatementStatus::BERECHNET, $abrechnung->status);
            self::assertCount(1, $abrechnung->lines);
        }

        $summe = $abrechnungen->sum(
            static fn (UnitStatement $abrechnung): int => $abrechnung->total_apportionable_cent
        );

        self::assertSame(120000, $summe);
    }

    public function test_anteile_folgen_der_wohnflaeche_und_ergeben_ein_guthaben(): void
    {
        $szenario = $this->szenario();

        app(CalculateBillingRun::class)->handle($szenario['billingRun'], $szenario['user']);

        $abrechnungA = UnitStatement::query()
            ->where('tenancy_id', $szenario['tenancies'][0]->getKey())
            ->firstOrFail();

        // 100 von 150 Quadratmetern ergeben zwei Drittel von 1.200,00 EUR.
        self::assertSame(80000, $abrechnungA->total_apportionable_cent);
        self::assertSame(288000, $abrechnungA->prepayment_actual_cent);
        self::assertSame(-208000, $abrechnungA->balance_cent);
        self::assertSame(StatementResultKind::GUTHABEN, $abrechnungA->result_kind);
    }

    public function test_rechenzeile_speichert_zaehler_nenner_und_zeitfaktor_als_zeichenkette(): void
    {
        $szenario = $this->szenario();

        app(CalculateBillingRun::class)->handle($szenario['billingRun'], $szenario['user']);

        $zeile = UnitStatementLine::query()->firstOrFail();

        self::assertIsString($zeile->numerator);
        self::assertIsString($zeile->denominator);
        self::assertIsString($zeile->time_factor);
        self::assertSame('150.000000', $zeile->denominator);
        self::assertSame('1.00000000', $zeile->time_factor);
        self::assertNotSame('', (string) $zeile->note);
    }

    public function test_der_lauf_verweist_auf_den_aktiven_stand_und_die_anzahl_der_abrechnungen(): void
    {
        $szenario = $this->szenario();

        $ergebnis = app(CalculateBillingRun::class)->handle($szenario['billingRun'], $szenario['user']);
        $lauf = $szenario['billingRun']->refresh();

        self::assertSame((string) $ergebnis->snapshot->getKey(), $lauf->active_calculation_snapshot_id);
        self::assertSame(2, $lauf->statement_count);
    }

    public function test_pruefaufgaben_der_regel_engine_werden_geschrieben(): void
    {
        $szenario = $this->szenario();

        app(CalculateBillingRun::class)->handle($szenario['billingRun'], $szenario['user']);

        self::assertGreaterThan(
            0,
            ValidationIssue::query()->where('billing_run_id', $szenario['billingRun']->getKey())->count()
        );
    }

    public function test_erneute_berechnung_erzeugt_eine_neue_version_und_ersetzt_die_alte(): void
    {
        $szenario = $this->szenario();
        $dienst = app(CalculateBillingRun::class);

        $erst = $dienst->handle($szenario['billingRun'], $szenario['user']);
        $zweit = $dienst->handle($szenario['billingRun']->refresh(), $szenario['user']);

        self::assertSame(1, $erst->snapshot->refresh()->version_number);
        self::assertSame(2, $zweit->snapshot->refresh()->version_number);
        self::assertSame(CalculationSnapshotStatus::ERSETZT, $erst->snapshot->refresh()->status);
        self::assertSame(
            (string) $zweit->snapshot->getKey(),
            $erst->snapshot->refresh()->replaced_by_snapshot_id
        );
        self::assertFalse($zweit->replacedPaidSnapshot);
    }

    public function test_neuberechnung_eines_bezahlten_laufs_laesst_den_gesperrten_stand_unveraendert(): void
    {
        $szenario = $this->szenario();
        $dienst = app(CalculateBillingRun::class);

        $bezahlt = $dienst->handle($szenario['billingRun'], $szenario['user'])->snapshot;

        $bezahlt->forceFill([
            'status' => CalculationSnapshotStatus::GESPERRT,
            'locked_at' => Carbon::parse('2026-03-01 10:00:00'),
        ])->save();

        $vorherEingabe = $bezahlt->refresh()->input;
        $vorherErgebnis = $bezahlt->result;
        $vorherHash = $bezahlt->hash;
        $vorherSperre = $bezahlt->locked_at;

        // Abrechnungsrelevante Korrektur nach der Zahlung.
        CostItem::query()
            ->whereKey($szenario['costItem']->getKey())
            ->update(['amount_cent' => 150000]);

        $neu = $dienst->handle($szenario['billingRun']->refresh(), $szenario['user']);
        $alt = $bezahlt->refresh();

        self::assertTrue($neu->replacedPaidSnapshot);
        self::assertSame(2, $neu->snapshot->refresh()->version_number);
        self::assertSame(CalculationSnapshotStatus::ERSETZT, $alt->status);
        self::assertSame($vorherEingabe, $alt->input);
        self::assertSame($vorherErgebnis, $alt->result);
        self::assertSame($vorherHash, $alt->hash);
        self::assertNotNull($alt->locked_at);
        self::assertTrue($vorherSperre?->equalTo($alt->locked_at));
        self::assertNotSame($vorherHash, $neu->snapshot->refresh()->hash);
    }

    public function test_erneute_berechnung_erzeugt_neue_abrechnungsversionen_und_ersetzt_die_alten(): void
    {
        $szenario = $this->szenario();
        $dienst = app(CalculateBillingRun::class);

        $dienst->handle($szenario['billingRun'], $szenario['user']);
        $dienst->handle($szenario['billingRun']->refresh(), $szenario['user']);

        $abrechnungen = UnitStatement::query()
            ->where('tenancy_id', $szenario['tenancies'][0]->getKey())
            ->orderBy('version_number')
            ->get();

        self::assertCount(2, $abrechnungen);
        self::assertSame(UnitStatementStatus::ERSETZT, $abrechnungen[0]->status);
        self::assertSame(UnitStatementStatus::BERECHNET, $abrechnungen[1]->status);
        self::assertSame(
            (string) $abrechnungen[1]->getKey(),
            $abrechnungen[0]->replaced_by_statement_id
        );
    }

    public function test_der_hash_haengt_an_eingabe_ergebnis_und_versionen(): void
    {
        $szenario = $this->szenario();
        $dienst = app(CalculateBillingRun::class);

        $erst = $dienst->handle($szenario['billingRun'], $szenario['user'])->snapshot->refresh();

        CostItem::query()
            ->whereKey($szenario['costItem']->getKey())
            ->update(['amount_cent' => 240000]);

        $zweit = $dienst->handle($szenario['billingRun']->refresh(), $szenario['user'])->snapshot->refresh();

        self::assertNotSame($erst->hash, $zweit->hash);
    }

    public function test_summen_des_snapshots_stimmen_mit_dem_ergebnis(): void
    {
        $szenario = $this->szenario();

        $ergebnis = app(CalculateBillingRun::class)->handle($szenario['billingRun'], $szenario['user']);
        $snapshot = $ergebnis->snapshot->refresh();

        self::assertSame(2, $snapshot->statement_count);
        self::assertSame(120000, $snapshot->total_apportionable_cent);
        self::assertSame(576000, $snapshot->total_prepayment_actual_cent);
        self::assertSame(-456000, $snapshot->total_balance_cent);
    }

    public function test_nur_ein_snapshot_ist_je_lauf_aktiv(): void
    {
        $szenario = $this->szenario();
        $dienst = app(CalculateBillingRun::class);

        $dienst->handle($szenario['billingRun'], $szenario['user']);
        $dienst->handle($szenario['billingRun']->refresh(), $szenario['user']);
        $dienst->handle($szenario['billingRun']->refresh(), $szenario['user']);

        $aktive = CalculationSnapshot::query()
            ->where('billing_run_id', $szenario['billingRun']->getKey())
            ->where('status', '!=', CalculationSnapshotStatus::ERSETZT->value)
            ->count();

        self::assertSame(1, $aktive);
        self::assertSame(
            3,
            CalculationSnapshot::query()->where('billing_run_id', $szenario['billingRun']->getKey())->count()
        );
    }
}
