<?php

declare(strict_types=1);

namespace Tests\Feature\Calculation;

use App\Application\Calculation\BillingRunInputAssembler;
use App\Application\Calculation\CalculationInputException;
use App\Application\Wizard\AllocationKeyWorkspace;
use App\Domain\Allocation\ConsumptionKey;
use App\Domain\Allocation\IndividualKey;
use App\Domain\Allocation\PersonDaysKey;
use App\Domain\Calculation\OccupancyKind;
use App\Domain\Calculation\StatementCalculator;
use App\Domain\Money\Money;
use App\Enums\AllocationKeySource;
use App\Enums\AllocationKeyType;
use App\Enums\CostItemStatus;
use App\Enums\HeatingSupplyCase;
use App\Enums\PrepaymentKind;
use App\Enums\ValueSource;
use App\Models\CostItem;
use App\Models\OccupancyPeriod;
use App\Models\Prepayment;
use App\Models\Tenancy;
use App\Models\Unit;
use App\Models\VacancyPeriod;

/**
 * Aufbau der Eingabe der Berechnungsengine aus den Modellen.
 *
 * Verbindlich geprüft: Dezimalwerte bleiben Zeichenketten, Geld ist Integer in
 * Cent, und ein fehlender Pflichtwert führt zu einer aussagekräftigen Ausnahme
 * statt zu einer Schätzung.
 */
final class BillingRunInputAssemblerTest extends CalculationTestCase
{
    public function test_eingabe_enthaelt_einheiten_nutzungszeitraeume_und_kosten(): void
    {
        $szenario = $this->szenario();

        $eingabe = app(BillingRunInputAssembler::class)->assemble($szenario['billingRun'])->input;

        self::assertCount(2, $eingabe->units);
        self::assertCount(2, $eingabe->occupancies);
        self::assertCount(1, $eingabe->costItems);
        self::assertSame('Beispielobjekt Sonnenweg 4', $eingabe->propertyLabel);
        self::assertSame(365, $eingabe->billingPeriod->days());
    }

    public function test_dezimalwerte_werden_nicht_nach_float_gecastet(): void
    {
        $szenario = $this->szenario();

        $eingabe = app(BillingRunInputAssembler::class)->assemble($szenario['billingRun'])->input;

        foreach ($eingabe->units as $einheit) {
            self::assertIsString($einheit->livingAreaSqm);
            self::assertIsString($einheit->heatedAreaSqm);
            self::assertIsString($einheit->coOwnershipShare);
            self::assertNotSame('double', gettype($einheit->livingAreaSqm));
        }

        $schluessel = array_values($eingabe->allocationKeys)[0];

        self::assertSame('string', gettype((string) $schluessel->denominator()));
        self::assertSame('150.000000', (string) $schluessel->denominator());
    }

    public function test_geldbetraege_sind_integer_in_cent(): void
    {
        $szenario = $this->szenario();

        $eingabe = app(BillingRunInputAssembler::class)->assemble($szenario['billingRun'])->input;

        self::assertInstanceOf(Money::class, $eingabe->costItems[0]->totalAmount);
        self::assertSame(120000, $eingabe->costItems[0]->totalAmount->cents);
        self::assertIsInt($eingabe->costItems[0]->totalAmount->cents);
        self::assertSame(288000, $eingabe->prepayments[0]->targetAmount->cents);
    }

    public function test_fehlende_vorauszahlung_erzeugt_eine_ausnahme_und_keine_schaetzung(): void
    {
        $szenario = $this->szenario();

        Prepayment::query()->where('billing_run_id', $szenario['billingRun']->getKey())->delete();

        $this->expectException(CalculationInputException::class);
        $this->expectExceptionMessage('Schritt 7 ist ein Pflichtschritt');

        app(BillingRunInputAssembler::class)->assemble($szenario['billingRun']->refresh());
    }

    public function test_fehlender_ist_wert_ohne_bestaetigte_annahme_erzeugt_eine_ausnahme(): void
    {
        $szenario = $this->szenario();

        Prepayment::query()
            ->where('billing_run_id', $szenario['billingRun']->getKey())
            ->update(['actual_cent' => null, 'assumed_equal_to_target' => false]);

        $this->expectException(CalculationInputException::class);
        $this->expectExceptionMessage('Es wird nichts geschätzt.');

        app(BillingRunInputAssembler::class)->assemble($szenario['billingRun']->refresh());
    }

    public function test_bestaetigte_annahme_ist_gleich_soll_wird_uebernommen_und_gekennzeichnet(): void
    {
        $szenario = $this->szenario();

        Prepayment::query()
            ->where('billing_run_id', $szenario['billingRun']->getKey())
            ->update([
                'actual_cent' => null,
                'assumed_equal_to_target' => true,
                'source' => ValueSource::SOLL_ANNAHME->value,
            ]);

        $eingabe = app(BillingRunInputAssembler::class)->assemble($szenario['billingRun']->refresh())->input;

        self::assertTrue($eingabe->prepayments[0]->assumedFromTarget);
        self::assertSame(288000, $eingabe->prepayments[0]->deductibleAmount()->cents);
        self::assertSame(ValueSource::SOLL_ANNAHME->label(), $eingabe->prepayments[0]->source);
    }

    public function test_fehlender_verteilerschluessel_erzeugt_eine_ausnahme(): void
    {
        $szenario = $this->szenario();

        $szenario['key']->delete();

        $this->expectException(CalculationInputException::class);
        $this->expectExceptionMessage('kein Verteilerschlüssel festgelegt');

        app(BillingRunInputAssembler::class)->assemble($szenario['billingRun']->refresh());
    }

    public function test_fehlender_schluesselwert_erzeugt_eine_ausnahme(): void
    {
        $szenario = $this->szenario();

        Unit::query()->whereKey($szenario['units'][1]->getKey())->update(['living_area_sqm' => null]);

        $this->expectException(CalculationInputException::class);
        $this->expectExceptionMessage('fehlt der Wert der Einheit Wohnung B');

        app(BillingRunInputAssembler::class)->assemble($szenario['billingRun']->refresh());
    }

    public function test_ohne_einheiten_ist_keine_abrechnung_moeglich(): void
    {
        $szenario = $this->szenario();

        Unit::query()->where('property_id', $szenario['property']->getKey())->forceDelete();

        $this->expectException(CalculationInputException::class);
        $this->expectExceptionMessage('keine Einheit erfasst');

        app(BillingRunInputAssembler::class)->assemble($szenario['billingRun']->fresh() ?? $szenario['billingRun']);
    }

    public function test_ohne_bestaetigte_kostenposition_bricht_der_aufbau_ab(): void
    {
        $szenario = $this->szenario();

        CostItem::query()->where('billing_run_id', $szenario['billingRun']->getKey())->delete();

        $this->expectException(CalculationInputException::class);
        $this->expectExceptionMessage('keine bestätigte Kostenposition');

        app(BillingRunInputAssembler::class)->assemble($szenario['billingRun']->refresh());
    }

    public function test_heizkostenfall_c_setzt_keine_heizkosten_an(): void
    {
        $szenario = $this->szenario();
        $heizung = $this->kategorie('HEIZUNG');

        CostItem::factory()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'billing_run_id' => $szenario['billingRun']->getKey(),
            'cost_category_id' => $heizung->getKey(),
            'amount_cent' => 500000,
            'is_heating_cost' => true,
            'status' => CostItemStatus::BESTAETIGT,
        ]);

        $szenario['billingRun']->forceFill([
            'heating_supply_case' => HeatingSupplyCase::DEZENTRAL,
        ])->save();

        $eingabe = app(BillingRunInputAssembler::class)->assemble($szenario['billingRun']->refresh())->input;

        self::assertCount(1, $eingabe->costItems);
        self::assertSame(120000, $eingabe->costItems[0]->totalAmount->cents);
    }

    public function test_leerstand_wird_als_nutzungszeitraum_des_eigentuemers_uebergeben(): void
    {
        $szenario = $this->szenario();

        Tenancy::query()
            ->whereKey($szenario['tenancies'][1]->getKey())
            ->update(['ends_on' => '2025-06-30']);

        VacancyPeriod::factory()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'unit_id' => $szenario['units'][1]->getKey(),
            'starts_on' => '2025-07-01',
            'ends_on' => '2025-12-31',
        ]);

        $eingabe = app(BillingRunInputAssembler::class)->assemble($szenario['billingRun']->refresh())->input;

        $leerstaende = array_values(array_filter(
            $eingabe->occupancies,
            static fn ($occupancy): bool => $occupancy->kind === OccupancyKind::VACANCY
        ));

        self::assertCount(1, $leerstaende);
        self::assertStringStartsWith(BillingRunInputAssembler::VACANCY_PREFIX, $leerstaende[0]->occupancyKey);
        self::assertSame(184, $leerstaende[0]->period->days());
    }

    public function test_zustellanschrift_wird_uebergeben(): void
    {
        $szenario = $this->szenario();

        $eingabe = app(BillingRunInputAssembler::class)->assemble($szenario['billingRun'])->input;

        self::assertIsString($eingabe->occupancies[0]->deliveryAddress);
        self::assertNotSame('', $eingabe->occupancies[0]->deliveryAddress);
    }

    public function test_personentage_werden_aus_den_belegungszeitraeumen_gebildet(): void
    {
        $szenario = $this->szenario();

        foreach ($szenario['tenancies'] as $index => $mietverhaeltnis) {
            OccupancyPeriod::factory()->create([
                'organization_id' => $szenario['organization']->getKey(),
                'tenancy_id' => $mietverhaeltnis->getKey(),
                'starts_on' => '2025-01-01',
                'ends_on' => '2025-12-31',
                'person_count' => $index === 0 ? 3 : 2,
            ]);
        }

        $szenario['key']->forceFill(['key_type' => AllocationKeyType::PERSONENTAGE, 'denominator' => null])->save();

        $eingabe = app(BillingRunInputAssembler::class)->assemble($szenario['billingRun']->refresh())->input;
        $schluessel = array_values($eingabe->allocationKeys)[0];

        self::assertInstanceOf(PersonDaysKey::class, $schluessel);
        self::assertSame('1095', (string) $schluessel->numeratorFor((string) $szenario['tenancies'][0]->getKey()));
        self::assertSame('1825', (string) $schluessel->denominator());
    }

    public function test_personentage_ohne_personenangabe_eines_mietverhaeltnisses_brechen_ab(): void
    {
        $szenario = $this->szenario();

        // Nur Mietpartei B hat einen Belegungszeitraum. Vorher erhielt A
        // stillschweigend das Gewicht null und B trug 100 Prozent.
        OccupancyPeriod::factory()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'tenancy_id' => $szenario['tenancies'][1]->getKey(),
            'starts_on' => '2025-01-01',
            'ends_on' => '2025-12-31',
            'person_count' => 2,
        ]);

        $szenario['key']->forceFill(['key_type' => AllocationKeyType::PERSONENTAGE, 'denominator' => null])->save();

        $this->expectException(CalculationInputException::class);
        $this->expectExceptionMessage('fehlen die Personenangaben des Mietverhältnisses Mietpartei A');

        app(BillingRunInputAssembler::class)->assemble($szenario['billingRun']->refresh());
    }

    public function test_personentage_mit_luecke_im_belegungszeitraum_brechen_ab(): void
    {
        $szenario = $this->szenario();

        foreach ($szenario['tenancies'] as $index => $mietverhaeltnis) {
            OccupancyPeriod::factory()->create([
                'organization_id' => $szenario['organization']->getKey(),
                'tenancy_id' => $mietverhaeltnis->getKey(),
                'starts_on' => '2025-01-01',
                // Mietpartei A ist nur bis Ende Juni erfasst.
                'ends_on' => $index === 0 ? '2025-06-30' : '2025-12-31',
                'person_count' => 2,
            ]);
        }

        $szenario['key']->forceFill(['key_type' => AllocationKeyType::PERSONENTAGE, 'denominator' => null])->save();

        $this->expectException(CalculationInputException::class);
        $this->expectExceptionMessage('Mietpartei A');

        app(BillingRunInputAssembler::class)->assemble($szenario['billingRun']->refresh());
    }

    public function test_personentage_mit_erfasstem_leerstand_brechen_ab_statt_den_anteil_auf_die_mieter_zu_verschieben(): void
    {
        $szenario = $this->szenario();

        // Wohnung B: Mieter bis 30.06.2025, danach erfasster Leerstand.
        Tenancy::query()
            ->whereKey($szenario['tenancies'][1]->getKey())
            ->update(['ends_on' => '2025-06-30']);

        VacancyPeriod::factory()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'unit_id' => $szenario['units'][1]->getKey(),
            'starts_on' => '2025-07-01',
            'ends_on' => '2025-12-31',
        ]);

        foreach ($szenario['tenancies'] as $index => $mietverhaeltnis) {
            OccupancyPeriod::factory()->create([
                'organization_id' => $szenario['organization']->getKey(),
                'tenancy_id' => $mietverhaeltnis->getKey(),
                'starts_on' => '2025-01-01',
                'ends_on' => $index === 0 ? '2025-12-31' : '2025-06-30',
                'person_count' => 2,
            ]);
        }

        $szenario['key']->forceFill(['key_type' => AllocationKeyType::PERSONENTAGE, 'denominator' => null])->save();

        // Vorher erhielt der Leerstand das Gewicht null; sein Anteil ging
        // stillschweigend auf die Mieter ueber.
        $this->expectException(CalculationInputException::class);
        $this->expectExceptionMessage('Wohnung B im Abrechnungszeitraum nicht durchgehend vermietet');

        app(BillingRunInputAssembler::class)->assemble($szenario['billingRun']->refresh());
    }

    public function test_personentage_mit_nicht_belegtem_zeitraum_brechen_ab(): void
    {
        $szenario = $this->szenario();

        // Wohnung B: Mieter bis 30.06.2025, danach weder Nachmieter noch
        // erfasster Leerstand.
        Tenancy::query()
            ->whereKey($szenario['tenancies'][1]->getKey())
            ->update(['ends_on' => '2025-06-30']);

        foreach ($szenario['tenancies'] as $index => $mietverhaeltnis) {
            OccupancyPeriod::factory()->create([
                'organization_id' => $szenario['organization']->getKey(),
                'tenancy_id' => $mietverhaeltnis->getKey(),
                'starts_on' => '2025-01-01',
                'ends_on' => $index === 0 ? '2025-12-31' : '2025-06-30',
                'person_count' => 2,
            ]);
        }

        $szenario['key']->forceFill(['key_type' => AllocationKeyType::PERSONENTAGE, 'denominator' => null])->save();

        $this->expectException(CalculationInputException::class);
        $this->expectExceptionMessage('Wohnung B');

        app(BillingRunInputAssembler::class)->assemble($szenario['billingRun']->refresh());
    }

    public function test_eine_nur_vorgeschlagene_kostenposition_wird_nicht_berechnet(): void
    {
        $szenario = $this->szenario();

        CostItem::factory()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'billing_run_id' => $szenario['billingRun']->getKey(),
            'cost_category_id' => $szenario['category']->getKey(),
            'description' => 'Von der KI erkannt, noch nicht geprüft',
            'amount_cent' => 999900,
            'status' => CostItemStatus::VORGESCHLAGEN,
            'confirmed_at' => null,
        ]);

        // Vorher wurde die unbestaetigte Position wie eine bestaetigte
        // verteilt, sobald der Aufbau an der Kostenpruefung vorbei aufgerufen
        // wurde.
        $this->expectException(CalculationInputException::class);
        $this->expectExceptionMessage('nur vorgeschlagen und noch nicht bestätigt');

        app(BillingRunInputAssembler::class)->assemble($szenario['billingRun']->refresh());
    }

    public function test_verbrauch_mit_erfasstem_leerstand_ohne_ablesewert_bricht_nicht_ab(): void
    {
        $szenario = $this->szenario();

        // Wohnung A: Mieter bis 31.08.2025, danach erfasster Leerstand.
        Tenancy::query()
            ->whereKey($szenario['tenancies'][0]->getKey())
            ->update(['ends_on' => '2025-08-31']);

        VacancyPeriod::factory()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'unit_id' => $szenario['units'][0]->getKey(),
            'starts_on' => '2025-09-01',
            'ends_on' => '2025-12-31',
        ]);

        $szenario['key']->forceFill([
            'key_type' => AllocationKeyType::VERBRAUCH,
            'source' => AllocationKeySource::MANUELL,
            'measurement_unit' => 'm3',
            'denominator' => null,
        ])->save();

        $this->schluesselwert($szenario['key'], '80.000', null, $szenario['tenancies'][0]);
        $this->schluesselwert($szenario['key'], '30.000', null, $szenario['tenancies'][1]);

        $eingabe = app(BillingRunInputAssembler::class)->assemble($szenario['billingRun']->refresh())->input;
        $schluessel = array_values($eingabe->allocationKeys)[0];

        self::assertInstanceOf(ConsumptionKey::class, $schluessel);
        self::assertSame(0, $schluessel->numeratorFor((string) $szenario['tenancies'][0]->getKey())->compareTo('80'));
        self::assertSame(0, $schluessel->denominator()->compareTo('110'));
        self::assertSame([], $schluessel->substituteParticipants());
    }

    public function test_verbrauch_einer_einheit_ohne_nutzung_bleibt_beim_eigentuemer(): void
    {
        $szenario = $this->szenario();

        // Wohnung B ist eigengenutzt: kein Mietverhältnis, kein Leerstand.
        Tenancy::query()->whereKey($szenario['tenancies'][1]->getKey())->delete();

        $szenario['key']->forceFill([
            'key_type' => AllocationKeyType::VERBRAUCH,
            'source' => AllocationKeySource::MANUELL,
            'measurement_unit' => 'm3',
            'denominator' => null,
        ])->save();

        $this->schluesselwert($szenario['key'], '80.000', null, $szenario['tenancies'][0]);
        $this->schluesselwert($szenario['key'], '30.000', $szenario['units'][1]);

        $eingabe = app(BillingRunInputAssembler::class)->assemble($szenario['billingRun']->refresh())->input;
        $schluessel = array_values($eingabe->allocationKeys)[0];

        // 80 von 110 m³ gehen an Mieter A, 30 m³ verbleiben als Restanteil
        // beim Eigentümer: 1.200,00 EUR × 80/110 = 872,73 EUR.
        self::assertInstanceOf(ConsumptionKey::class, $schluessel);
        self::assertSame(0, $schluessel->denominator()->compareTo('110'));

        $ergebnis = app(StatementCalculator::class)->calculate($eingabe);

        self::assertSame(87273, $ergebnis->statements[0]->allocableTotal->cents);
        self::assertSame(32727, $ergebnis->ownerOverview->residualTotal->cents);
        self::assertTrue($ergebnis->ownerOverview->isBalanced());
    }

    public function test_individueller_schluessel_nutzt_den_stammwert_der_einheit(): void
    {
        $szenario = $this->szenario();

        $szenario['key']->forceFill(['key_type' => AllocationKeyType::INDIVIDUELL_1, 'denominator' => null])->save();

        $eingabe = app(BillingRunInputAssembler::class)->assemble($szenario['billingRun']->refresh())->input;
        $schluessel = array_values($eingabe->allocationKeys)[0];

        self::assertInstanceOf(IndividualKey::class, $schluessel);
        self::assertSame('3.0000', (string) $schluessel->denominator());
    }

    public function test_verbrauch_ohne_zwischenablesung_verlangt_eine_bestaetigte_ersatzverteilung(): void
    {
        $szenario = $this->zweiMietverhaeltnisseInEinerEinheit();

        $this->expectException(CalculationInputException::class);
        $this->expectExceptionMessage('ausdrücklich zu bestätigen');

        app(BillingRunInputAssembler::class)->assemble($szenario['billingRun']->refresh());
    }

    public function test_bestaetigte_ersatzverteilung_kennzeichnet_die_beteiligten(): void
    {
        $szenario = $this->zweiMietverhaeltnisseInEinerEinheit();

        app(AllocationKeyWorkspace::class)->confirmSubstituteDistribution(
            $szenario['billingRun'],
            (string) $szenario['units'][0]->getKey(),
            $szenario['user'],
        );

        $eingabe = app(BillingRunInputAssembler::class)->assemble($szenario['billingRun']->refresh())->input;
        $schluessel = array_values($eingabe->allocationKeys)[0];

        self::assertInstanceOf(ConsumptionKey::class, $schluessel);
        self::assertCount(2, $schluessel->substituteParticipants());
    }

    public function test_rueckabbildung_auf_die_datenbankschluessel_ist_vollstaendig(): void
    {
        $szenario = $this->szenario();

        $aufbau = app(BillingRunInputAssembler::class)->assemble($szenario['billingRun']);

        self::assertSame(
            (string) $szenario['tenancies'][0]->getKey(),
            $aufbau->tenancyId((string) $szenario['tenancies'][0]->getKey())
        );
        self::assertSame(
            (string) $szenario['units'][0]->getKey(),
            $aufbau->unitId((string) $szenario['units'][0]->getKey())
        );
        self::assertSame(
            (string) $szenario['category']->getKey(),
            $aufbau->costCategoryId((string) $szenario['costItem']->getKey())
        );
    }

    /**
     * Eine Einheit mit Mieterwechsel und einem Verbrauchsschlüssel, dessen
     * Wert nur für die Einheit vorliegt.
     *
     * @return array<string, mixed>
     */
    private function zweiMietverhaeltnisseInEinerEinheit(): array
    {
        $szenario = $this->szenario();

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

        $szenario['key']->forceFill([
            'key_type' => AllocationKeyType::VERBRAUCH,
            'source' => AllocationKeySource::MANUELL,
            'measurement_unit' => 'm3',
            'denominator' => null,
        ])->save();

        $this->schluesselwert($szenario['key'], '120.000', $szenario['units'][0]);
        $this->schluesselwert($szenario['key'], '60.000', $szenario['units'][1]);

        $szenario['tenancies'][] = $nachmieter;

        return $szenario;
    }

    public function test_vorauszahlung_summiert_betriebskosten_und_heizkosten(): void
    {
        $szenario = $this->szenario();

        Prepayment::query()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'billing_run_id' => $szenario['billingRun']->getKey(),
            'tenancy_id' => $szenario['tenancies'][0]->getKey(),
            'kind' => PrepaymentKind::HEIZKOSTEN,
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'target_cent' => 108000,
            'actual_cent' => 108000,
            'source' => ValueSource::MIETVERTRAG,
            'assumed_equal_to_target' => false,
        ]);

        $eingabe = app(BillingRunInputAssembler::class)->assemble($szenario['billingRun']->refresh())->input;

        $vorauszahlung = $eingabe->prepaymentFor((string) $szenario['tenancies'][0]->getKey());

        self::assertNotNull($vorauszahlung);
        self::assertSame(396000, $vorauszahlung->targetAmount->cents);
        self::assertSame(396000, $vorauszahlung->deductibleAmount()->cents);
    }
}
