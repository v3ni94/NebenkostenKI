<?php

declare(strict_types=1);

namespace Tests\Feature\Rules;

use App\Domain\Money\Money;
use App\Enums\ApportionmentStatus;
use App\Enums\Co2ShareStatus;
use App\Enums\HeatingSupplyCase;
use App\Models\AllocationKey;
use App\Models\AllocationKeyValue;
use App\Models\BillingRun;
use App\Models\CostItem;
use App\Models\HeatingStatement;
use App\Models\Prepayment;
use App\Models\Tenancy;
use App\Models\Unit;
use App\Rules\Engine\RuleContextFactory;
use App\Rules\Engine\RuleEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Aufbau des Regelkontexts aus den Modellen eines Abrechnungslaufs.
 */
final class RuleContextFactoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function der_kontext_uebernimmt_zeitraum_kosten_und_einheiten(): void
    {
        $billingRun = BillingRun::factory()->create();
        $unit = Unit::factory()->create([
            'property_id' => $billingRun->property_id,
            'organization_id' => $billingRun->organization_id,
            'living_area_sqm' => '75.0000',
        ]);
        Tenancy::factory()->create([
            'unit_id' => $unit->getKey(),
            'property_id' => $billingRun->property_id,
            'organization_id' => $billingRun->organization_id,
            'starts_on' => '2025-01-01',
            'ends_on' => null,
        ]);
        CostItem::factory()->create([
            'billing_run_id' => $billingRun->getKey(),
            'organization_id' => $billingRun->organization_id,
            'description' => 'Gebäudereinigung',
            'amount_cent' => 120000,
            'service_period_start' => '2025-01-01',
            'service_period_end' => '2025-12-31',
            'apportionment_status' => ApportionmentStatus::UMLAGEFAEHIG,
        ]);

        $context = (new RuleContextFactory)->fromBillingRun($billingRun);

        $this->assertSame('2025-01-01', $context->billingPeriod->startIso());
        $this->assertSame('2025-12-31', $context->billingPeriod->endIso());
        $this->assertCount(1, $context->costItems);
        $this->assertSame(120000, $context->costItems[0]->amount->cents);
        $this->assertCount(1, $context->units);
        $this->assertCount(1, $context->tenancies);
        $this->assertSame((string) $unit->getKey(), $context->tenancies[0]->unitKey);
    }

    #[Test]
    public function der_kontext_uebernimmt_schluessel_vorauszahlungen_und_heizkosten(): void
    {
        $billingRun = BillingRun::factory()->create();
        $unit = Unit::factory()->create([
            'property_id' => $billingRun->property_id,
            'organization_id' => $billingRun->organization_id,
        ]);
        $tenancy = Tenancy::factory()->create([
            'unit_id' => $unit->getKey(),
            'property_id' => $billingRun->property_id,
            'organization_id' => $billingRun->organization_id,
        ]);
        $key = AllocationKey::factory()->create([
            'billing_run_id' => $billingRun->getKey(),
            'organization_id' => $billingRun->organization_id,
            'denominator' => '150.0000',
        ]);
        AllocationKeyValue::factory()->create([
            'allocation_key_id' => $key->getKey(),
            'organization_id' => $billingRun->organization_id,
            'unit_id' => $unit->getKey(),
            'numerator' => '75.0000',
        ]);
        Prepayment::factory()->create([
            'billing_run_id' => $billingRun->getKey(),
            'organization_id' => $billingRun->organization_id,
            'tenancy_id' => $tenancy->getKey(),
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'target_cent' => 180000,
            'actual_cent' => 180000,
        ]);
        HeatingStatement::factory()->create([
            'billing_run_id' => $billingRun->getKey(),
            'organization_id' => $billingRun->organization_id,
            'supply_case' => HeatingSupplyCase::EXTERN_ABGERECHNET,
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'total_cost_cent' => 720000,
            'co2_share_status' => Co2ShareStatus::ENTHALTEN,
        ]);

        $context = (new RuleContextFactory)->fromBillingRun($billingRun);

        $this->assertCount(1, $context->allocationKeys);
        $this->assertSame('150.000000', $context->allocationKeys[0]->denominator);
        $this->assertSame([(string) $unit->getKey() => '75.000000'], $context->allocationKeys[0]->numerators);
        $this->assertCount(1, $context->prepayments);
        $this->assertSame(180000, $context->prepayments[0]->target->cents);
        $this->assertCount(1, $context->heatingStatements);
        $this->assertSame(Co2ShareStatus::ENTHALTEN, $context->heatingStatements[0]->co2ShareStatus);
    }

    #[Test]
    public function der_kontext_uebernimmt_toleranzen_und_umgebung_aus_der_konfiguration(): void
    {
        config()->set('smartabrechnen.tolerances.checksum_cent', 250);
        config()->set('smartabrechnen.uploads.malware_scanner.driver', 'clamav');

        $billingRun = BillingRun::factory()->create();
        $context = (new RuleContextFactory)->fromBillingRun($billingRun);

        $this->assertSame(250, $context->tolerances->checksumCent);
        $this->assertSame('clamav', $context->environment->malwareScannerDriver);
    }

    #[Test]
    public function die_engine_laeuft_vollstaendig_gegen_einen_abrechnungslauf(): void
    {
        $billingRun = BillingRun::factory()->create();
        Unit::factory()->create([
            'property_id' => $billingRun->property_id,
            'organization_id' => $billingRun->organization_id,
            'living_area_sqm' => null,
        ]);

        $context = (new RuleContextFactory)->fromBillingRun($billingRun);
        $report = (new RuleEngine)->runForContext($context);

        $this->assertSame('2023.1', $report->rulesetVersion);
        $this->assertTrue($report->blocksFinalization());
        $this->assertSame(
            'SCHLUESSELWERTE_UNVOLLSTAENDIG',
            $report->blockers()[0]->ruleCode
        );
    }

    #[Test]
    public function eine_bezahlte_abrechnung_kennt_den_gezahlten_betrag(): void
    {
        $billingRun = BillingRun::factory()->create(['price_total_gross_cent' => 7470]);

        $context = (new RuleContextFactory)->fromBillingRun($billingRun);

        $this->assertInstanceOf(Money::class, $context->finalizationState->expectedAmount);
        $this->assertSame(7470, $context->finalizationState->expectedAmount->cents);
        $this->assertSame(0, $context->finalizationState->finalizedVersionCount);
    }
}
