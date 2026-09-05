<?php

declare(strict_types=1);

namespace Tests\Feature\Calculation;

use App\Application\Calculation\BillingRunInputAssembler;
use App\Application\Calculation\CalculateBillingRun;
use App\Application\Calculation\CalculationInputException;
use App\Domain\Calculation\Result\CheckCode;
use App\Domain\Calculation\Result\UnitStatementResult;
use App\Domain\Calculation\StatementCalculator;
use App\Enums\AllocationKeySource;
use App\Enums\AllocationKeyType;
use App\Enums\CostItemStatus;
use App\Models\CostItem;
use App\Models\Tenancy;
use App\Models\UnitStatement;
use App\Models\VacancyPeriod;
use Tests\Feature\Review\ReviewTestCase;

/**
 * Direktzuordnung in der Berechnung.
 *
 * Verbindlich geprüft mit konkreten Cent-Beträgen:
 *   - eine in der Kostenprüfung direkt einer Einheit zugeordnete Position
 *     belastet ausschließlich diese Einheit, taggenau je Nutzungszeitraum,
 *   - die Zähler einer Direktzuordnung sind Festbeträge; ein nicht
 *     zugeordneter Rest verbleibt beim Eigentümer und wird nie auf die
 *     übrigen Mieter hochgerechnet,
 *   - übersteigen die zugeordneten Beträge den Positionsbetrag, bricht der
 *     Aufbau mit einer verständlichen Prüfaufgabe ab.
 */
final class DirectAssignmentCalculationTest extends CalculationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ReviewTestCase::registriereRouten();
        app('router')->getRoutes()->refreshNameLookups();
    }

    public function test_direkt_zugeordnete_position_belastet_nur_die_zugeordnete_einheit(): void
    {
        $szenario = $this->szenario();
        $grundsteuer = $this->kategorie('GRUNDSTEUER');

        /** @var CostItem $bescheid */
        $bescheid = CostItem::factory()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'billing_run_id' => $szenario['billingRun']->getKey(),
            'cost_category_id' => $grundsteuer->getKey(),
            'description' => 'Grundsteuer Wohnung A',
            'amount_cent' => 50000,
            'status' => CostItemStatus::BESTAETIGT,
            'confirmed_at' => now(),
        ]);

        // Schritt 8 hat für die Kostenart Wohnfläche festgelegt. Ohne
        // Direktzuordnung würde die Position 100:50 verteilt.
        $this->schluessel($szenario['billingRun'], $grundsteuer, AllocationKeyType::WOHNFLAECHE);

        $this->actingAs($szenario['user'])->post(route('portal.pruefung.kosten.einheit', [
            'billingRun' => $szenario['billingRun']->getKey(),
            'costItem' => $bescheid->getKey(),
        ]), ['unit_id' => $szenario['units'][0]->getKey()])->assertRedirect();

        $ergebnis = app(CalculateBillingRun::class)->handle($szenario['billingRun']->refresh(), $szenario['user']);

        // Gebäudereinigung 1.200,00 EUR nach Wohnfläche 100:50 ergibt 800,00
        // und 400,00 EUR. Die Grundsteuer 500,00 EUR geht vollständig an A.
        self::assertSame(130000, $this->abrechnung($ergebnis->result->statements, $szenario['tenancies'][0])->allocableTotal->cents);
        self::assertSame(40000, $this->abrechnung($ergebnis->result->statements, $szenario['tenancies'][1])->allocableTotal->cents);
        self::assertSame(0, $ergebnis->result->ownerOverview->residualTotal->cents);
        self::assertTrue($ergebnis->result->ownerOverview->isBalanced());

        $zeile = UnitStatement::query()
            ->where('tenancy_id', $szenario['tenancies'][0]->getKey())
            ->firstOrFail()
            ->lines
            ->firstWhere('category_label', $grundsteuer->name);

        self::assertNotNull($zeile);
        self::assertSame(50000, $zeile->share_cent);
        self::assertSame(AllocationKeyType::DIREKT, $zeile->allocation_key_type);
    }

    public function test_direkt_zugeordnete_position_wird_bei_mieterwechsel_taggenau_geteilt(): void
    {
        $szenario = $this->szenario();
        $grundsteuer = $this->kategorie('GRUNDSTEUER');

        Tenancy::query()
            ->whereKey($szenario['tenancies'][0]->getKey())
            ->update(['ends_on' => '2025-06-30']);

        /** @var Tenancy $nachmieter */
        $nachmieter = Tenancy::factory()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'property_id' => $szenario['property']->getKey(),
            'unit_id' => $szenario['units'][0]->getKey(),
            'tenant_display_name' => 'Mietpartei C',
            'starts_on' => '2025-07-01',
            'ends_on' => null,
        ]);

        $this->vorauszahlung($szenario['billingRun'], $nachmieter);

        CostItem::factory()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'billing_run_id' => $szenario['billingRun']->getKey(),
            'cost_category_id' => $grundsteuer->getKey(),
            'description' => 'Grundsteuer Wohnung A',
            'amount_cent' => 36500,
            'status' => CostItemStatus::BESTAETIGT,
            'confirmed_at' => now(),
            'direct_unit_id' => $szenario['units'][0]->getKey(),
        ]);

        $this->schluessel($szenario['billingRun'], $grundsteuer, AllocationKeyType::WOHNFLAECHE);

        $eingabe = app(BillingRunInputAssembler::class)->assemble($szenario['billingRun']->refresh())->input;
        $ergebnis = app(StatementCalculator::class)->calculate($eingabe);

        // 365,00 EUR auf 181 und 184 Tage: exakt 181,00 und 184,00 EUR.
        // Gebäudereinigung 1.200,00 EUR nach Wohnfläche: A-Anteil 800,00 EUR,
        // davon 181/365 = 396,71 EUR und 184/365 = 403,29 EUR.
        self::assertSame(39671 + 18100, $this->abrechnung($ergebnis->statements, $szenario['tenancies'][0])->allocableTotal->cents);
        self::assertSame(40329 + 18400, $this->abrechnung($ergebnis->statements, $nachmieter)->allocableTotal->cents);
        self::assertSame(40000, $this->abrechnung($ergebnis->statements, $szenario['tenancies'][1])->allocableTotal->cents);
        self::assertTrue($ergebnis->ownerOverview->isBalanced());
    }

    public function test_direktzuordnung_uebernimmt_festbetraege_und_weist_den_rest_dem_eigentuemer_zu(): void
    {
        $szenario = $this->szenario();

        // Kostenposition 1.200,00 EUR, direkt zugeordnet: A 300,00 EUR,
        // B 500,00 EUR. Erwartet: A 300,00, B 500,00, 400,00 EUR Rest beim
        // Eigentümer. Vorher: A 450,00 und B 750,00 EUR (proportional
        // hochgerechnet).
        $szenario['key']->forceFill([
            'key_type' => AllocationKeyType::DIREKT,
            'source' => AllocationKeySource::MANUELL,
            'denominator' => null,
        ])->save();

        $this->schluesselwert($szenario['key'], '30000', null, $szenario['tenancies'][0]);
        $this->schluesselwert($szenario['key'], '50000', null, $szenario['tenancies'][1]);

        $ergebnis = app(CalculateBillingRun::class)->handle($szenario['billingRun']->refresh(), $szenario['user']);

        $abrechnungA = $this->abrechnung($ergebnis->result->statements, $szenario['tenancies'][0]);

        self::assertSame(30000, $abrechnungA->allocableTotal->cents);
        self::assertSame(50000, $this->abrechnung($ergebnis->result->statements, $szenario['tenancies'][1])->allocableTotal->cents);
        self::assertSame(40000, $ergebnis->result->ownerOverview->residualTotal->cents);
        self::assertTrue($ergebnis->result->ownerOverview->isBalanced());
        self::assertSame('Direktzuordnung 300,00 EUR von 1.200,00 EUR', $abrechnungA->lines[0]->allocationExplanation);

        self::assertTrue($ergebnis->result->hasFinding(CheckCode::UNALLOCATED_RESIDUAL));
    }

    public function test_direktzuordnung_ueber_dem_positionsbetrag_bricht_ab_statt_umzuverteilen(): void
    {
        $szenario = $this->szenario();

        $szenario['key']->forceFill([
            'key_type' => AllocationKeyType::DIREKT,
            'source' => AllocationKeySource::MANUELL,
            'denominator' => null,
        ])->save();

        // 700,00 + 600,00 EUR übersteigen die Position von 1.200,00 EUR.
        $this->schluesselwert($szenario['key'], '70000', null, $szenario['tenancies'][0]);
        $this->schluesselwert($szenario['key'], '60000', null, $szenario['tenancies'][1]);

        $this->expectException(CalculationInputException::class);
        $this->expectExceptionMessage('ordnet insgesamt 1.300,00 EUR zu, die zugehörige Kostenposition beträgt aber nur 1.200,00 EUR');

        app(BillingRunInputAssembler::class)->assemble($szenario['billingRun']->refresh());
    }

    public function test_der_nenner_der_kategoriebezogenen_direktzuordnung_zaehlt_keine_positionen_mit_eigener_zuordnung(): void
    {
        $szenario = $this->szenario();
        $grundsteuer = $this->kategorie('GRUNDSTEUER');

        // Position 1: 500,00 EUR, laeuft ueber den Kategorieschluessel
        // (Direktzuordnung: 500,00 EUR an A).
        CostItem::factory()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'billing_run_id' => $szenario['billingRun']->getKey(),
            'cost_category_id' => $grundsteuer->getKey(),
            'description' => 'Grundsteuer Wohnung A',
            'amount_cent' => 50000,
            'status' => CostItemStatus::BESTAETIGT,
            'confirmed_at' => now(),
        ]);

        // Position 2: 300,00 EUR, in der Kostenpruefung direkt Wohnung B
        // zugeordnet; sie laeuft nicht ueber den Kategorieschluessel.
        /** @var CostItem $bescheidB */
        $bescheidB = CostItem::factory()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'billing_run_id' => $szenario['billingRun']->getKey(),
            'cost_category_id' => $grundsteuer->getKey(),
            'description' => 'Grundsteuer Wohnung B',
            'amount_cent' => 30000,
            'status' => CostItemStatus::BESTAETIGT,
            'confirmed_at' => now(),
        ]);

        $this->actingAs($szenario['user'])->post(route('portal.pruefung.kosten.einheit', [
            'billingRun' => $szenario['billingRun']->getKey(),
            'costItem' => $bescheidB->getKey(),
        ]), ['unit_id' => $szenario['units'][1]->getKey()])->assertRedirect();

        $direkt = $this->schluessel($szenario['billingRun'], $grundsteuer, AllocationKeyType::DIREKT, AllocationKeySource::MANUELL);
        $this->schluesselwert($direkt, '50000', null, $szenario['tenancies'][0]);

        $ergebnis = app(CalculateBillingRun::class)->handle($szenario['billingRun']->refresh(), $szenario['user']);

        // Vorher zaehlte Position 2 in den Nenner der Kategorie (800,00 EUR):
        // A erhielt nur 312,50 EUR der Position 1, 187,50 EUR blieben als
        // Rest beim Eigentuemer. Richtig: A 500,00 EUR, B 300,00 EUR, kein Rest.
        self::assertSame(80000 + 50000, $this->abrechnung($ergebnis->result->statements, $szenario['tenancies'][0])->allocableTotal->cents);
        self::assertSame(40000 + 30000, $this->abrechnung($ergebnis->result->statements, $szenario['tenancies'][1])->allocableTotal->cents);
        self::assertSame(0, $ergebnis->result->ownerOverview->residualTotal->cents);
        self::assertTrue($ergebnis->result->ownerOverview->isBalanced());
    }

    public function test_leerstand_der_direkt_zugeordneten_einheit_bleibt_beim_eigentuemer(): void
    {
        $szenario = $this->szenario();
        $grundsteuer = $this->kategorie('GRUNDSTEUER');

        Tenancy::query()
            ->whereKey($szenario['tenancies'][0]->getKey())
            ->update(['ends_on' => '2025-06-30']);

        VacancyPeriod::factory()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'unit_id' => $szenario['units'][0]->getKey(),
            'starts_on' => '2025-07-01',
            'ends_on' => '2025-12-31',
        ]);

        CostItem::factory()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'billing_run_id' => $szenario['billingRun']->getKey(),
            'cost_category_id' => $grundsteuer->getKey(),
            'description' => 'Grundsteuer Wohnung A',
            'amount_cent' => 36500,
            'status' => CostItemStatus::BESTAETIGT,
            'confirmed_at' => now(),
            'direct_unit_id' => $szenario['units'][0]->getKey(),
        ]);

        $this->schluessel($szenario['billingRun'], $grundsteuer, AllocationKeyType::WOHNFLAECHE);

        $ergebnis = app(CalculateBillingRun::class)->handle($szenario['billingRun']->refresh(), $szenario['user']);

        // Grundsteuer 365,00 EUR: 181 Tage Mieter A = 181,00 EUR, 184 Tage
        // Leerstand = 184,00 EUR beim Eigentümer. Mieter B erhält nichts.
        self::assertSame(39671 + 18100, $this->abrechnung($ergebnis->result->statements, $szenario['tenancies'][0])->allocableTotal->cents);
        self::assertSame(40000, $this->abrechnung($ergebnis->result->statements, $szenario['tenancies'][1])->allocableTotal->cents);
        self::assertSame(40329 + 18400, $ergebnis->result->ownerOverview->vacancyTotal->cents);
        self::assertTrue($ergebnis->result->ownerOverview->isBalanced());
    }

    /**
     * @param  list<UnitStatementResult>  $statements
     */
    private function abrechnung(array $statements, Tenancy $mietverhaeltnis): UnitStatementResult
    {
        foreach ($statements as $statement) {
            if ($statement->occupancyKey === (string) $mietverhaeltnis->getKey()) {
                return $statement;
            }
        }

        self::fail('Keine Abrechnung für das Mietverhältnis gefunden.');
    }
}
