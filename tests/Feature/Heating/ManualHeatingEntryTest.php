<?php

declare(strict_types=1);

namespace Tests\Feature\Heating;

use App\Application\Calculation\BillingRunInputAssembler;
use App\Domain\Calculation\StatementCalculator;
use App\Enums\AllocationKeyType;
use App\Enums\HeatingSupplyCase;
use App\Enums\PrepaymentKind;
use App\Enums\ValueSource;
use App\Models\AllocationKey;
use App\Models\AllocationKeyValue;
use App\Models\BillingRun;
use App\Models\CostItem;
use App\Models\HeatingStatement;
use App\Models\HeatingStatementLine;
use App\Models\Organization;
use App\Models\Prepayment;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Unit;
use App\Models\User;
use App\Models\ValidationIssue;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;

/**
 * Manuelle Heizkostenerfassung, Fall B.
 *
 * Die Plattform uebernimmt die eingetragenen Betraege unveraendert. Sie
 * rechnet sie nicht nach und verteilt sie nicht selbst.
 */
final class ManualHeatingEntryTest extends ManualHeatingTestCase
{
    public function test_die_eingabemaske_nennt_die_rechtlichen_hinweise(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $antwort = $this->actingAs($mandant['user'])
            ->get(route('portal.pruefung.heizkosten.erfassung', ['billingRun' => $lauf->getKey()]));

        $antwort->assertOk();
        $antwort->assertSee('übernimmt die von Ihnen eingetragenen Beträge unverändert', false);
        $antwort->assertSee('Verteilung nach Grund- und Verbrauchskosten sowie die CO2-Kostenaufteilung nicht', false);
        $antwort->assertSee('verbrauchsabhängige Abrechnung', false);
        $antwort->assertSee('Kürzungsrecht zustehen', false);
        $antwort->assertSee('Messdienstleister zu beauftragen', false);
        $antwort->assertSee('Verantwortlich für die Richtigkeit der eingetragenen Werte ist der Vermieter', false);
    }

    public function test_die_maske_weist_ohne_gesamtbetrag_auf_die_fehlende_gegenprobe_hin(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $antwort = $this->actingAs($mandant['user'])
            ->get(route('portal.pruefung.heizkosten.erfassung', ['billingRun' => $lauf->getKey()]));

        $antwort->assertOk();
        $antwort->assertSee('keine Gegenprobe der Einzelbeträge möglich', false);
    }

    public function test_die_erfassung_speichert_die_betraege_exakt_in_cent(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->speichern($mandant, $lauf, [
            (string) $mandant['unit']->getKey() => [
                'heizung' => '1.234,56',
                'warmwasser' => '300,44',
                'co2_vermieter' => '40,00',
                'co2_mieter' => '60,00',
                'sonstige' => '5,00',
            ],
        ])->assertRedirect();

        /** @var HeatingStatement $abrechnung */
        $abrechnung = HeatingStatement::query()->firstOrFail();

        self::assertSame(HeatingSupplyCase::ZENTRAL_OHNE_EXTERN, $abrechnung->supply_case);
        self::assertTrue((bool) $abrechnung->getAttribute('manual_entry'));
        self::assertSame(123456, (int) $abrechnung->getAttribute('heating_cost_cent'));
        self::assertSame(30044, (int) $abrechnung->getAttribute('warm_water_cost_cent'));
        self::assertSame(4000, (int) $abrechnung->getAttribute('co2_landlord_cent'));
        self::assertSame(6000, (int) $abrechnung->getAttribute('co2_tenant_cent'));
        self::assertSame(500, (int) $abrechnung->getAttribute('other_cost_cent'));
        self::assertSame(164000, (int) $abrechnung->getAttribute('checksum_lines_total_cent'));
    }

    public function test_die_herkunft_der_berechnung_wird_gespeichert(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->speichern($mandant, $lauf, $this->betraege($mandant), [
            'herkunft' => 'Eigene Tabellenkalkulation vom 15.03.2026, Grundkostenanteil 30 Prozent',
        ]);

        /** @var HeatingStatement $abrechnung */
        $abrechnung = HeatingStatement::query()->firstOrFail();

        self::assertSame(
            'Eigene Tabellenkalkulation vom 15.03.2026, Grundkostenanteil 30 Prozent',
            $abrechnung->getAttribute('calculation_origin')
        );
    }

    public function test_die_uebernahme_erfolgt_als_direktzuordnung_je_einheit(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->speichern($mandant, $lauf, $this->betraege($mandant));

        /** @var CostItem $position */
        $position = CostItem::query()->where('manual_heating_entry', true)
            ->where('is_warm_water_cost', false)->firstOrFail();

        self::assertSame(106000, (int) $position->getAttribute('amount_cent'));

        /** @var AllocationKey $schluessel */
        $schluessel = AllocationKey::query()->where('cost_item_id', $position->getKey())->firstOrFail();

        self::assertSame(AllocationKeyType::DIREKT, $schluessel->getAttribute('key_type'));

        /** @var AllocationKeyValue $wert */
        $wert = AllocationKeyValue::query()->where('allocation_key_id', $schluessel->getKey())->firstOrFail();

        self::assertSame((string) $mandant['tenancy']->getKey(), $wert->getAttribute('tenancy_id'));
        self::assertNull($wert->getAttribute('unit_id'));
        self::assertSame('106000', (string) (int) $wert->getAttribute('numerator'));
    }

    public function test_warmwasser_wird_als_eigene_position_uebernommen(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->speichern($mandant, $lauf, $this->betraege($mandant));

        /** @var CostItem $position */
        $position = CostItem::query()->where('manual_heating_entry', true)
            ->where('is_warm_water_cost', true)->firstOrFail();

        self::assertSame(30000, (int) $position->getAttribute('amount_cent'));
    }

    public function test_bei_mieterwechsel_wird_der_betrag_zeitanteilig_verteilt(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $mandant['tenancy']->forceFill(['starts_on' => '2025-01-01', 'ends_on' => '2025-06-30'])->save();

        /** @var Tenancy $nachfolger */
        $nachfolger = Tenancy::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'property_id' => $mandant['property']->getKey(),
            'unit_id' => $mandant['unit']->getKey(),
            'starts_on' => '2025-07-01',
            'ends_on' => '2025-12-31',
        ]);

        $this->speichern($mandant, $lauf, [
            (string) $mandant['unit']->getKey() => [
                'heizung' => '1.200,00',
                'warmwasser' => '0,00',
                'co2_vermieter' => '0,00',
                'co2_mieter' => '0,00',
                'sonstige' => '0,00',
            ],
        ]);

        $zeilen = HeatingStatementLine::query()->orderBy('usage_period_label')->get();

        self::assertCount(2, $zeilen);
        self::assertSame(120000, (int) $zeilen->sum('share_heating_cent'));

        $erste = $zeilen->firstWhere('tenancy_id', (string) $mandant['tenancy']->getKey());
        $zweite = $zeilen->firstWhere('tenancy_id', (string) $nachfolger->getKey());

        self::assertNotNull($erste);
        self::assertNotNull($zweite);
        self::assertSame(181, (int) $erste->getAttribute('usage_days'));
        self::assertSame(184, (int) $zweite->getAttribute('usage_days'));
        self::assertSame(59507, (int) $erste->getAttribute('share_heating_cent'));
        self::assertSame(60493, (int) $zweite->getAttribute('share_heating_cent'));
    }

    public function test_betrag_einer_unbelegten_einheit_bleibt_beim_eigentuemer(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);
        $this->vorauszahlung($lauf, $mandant['tenancy']);

        /** @var Unit $leer */
        $leer = Unit::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'property_id' => $mandant['property']->getKey(),
            'label' => 'Wohnung 2',
        ]);

        // Einheit A: Mieter ganzjährig, 1.000,00 EUR. Einheit B: ganzjährig
        // ohne Mietverhältnis, 500,00 EUR.
        $this->speichern($mandant, $lauf, [
            (string) $mandant['unit']->getKey() => ['heizung' => '1.000,00'],
            (string) $leer->getKey() => ['heizung' => '500,00'],
        ])->assertRedirect();

        /** @var CostItem $position */
        $position = CostItem::query()->where('manual_heating_entry', true)->firstOrFail();

        self::assertSame(150000, (int) $position->getAttribute('amount_cent'));

        $werte = AllocationKeyValue::query()
            ->whereIn('allocation_key_id', AllocationKey::query()->where('cost_item_id', $position->getKey())->select('id'))
            ->get();

        self::assertCount(1, $werte);
        self::assertSame('100000', (string) (int) $werte->first()?->getAttribute('numerator'));

        $ergebnis = app(StatementCalculator::class)->calculate(
            app(BillingRunInputAssembler::class)->assemble($lauf->refresh())->input
        );

        // Mieter A zahlt 1.000,00 EUR, die 500,00 EUR der leeren Einheit
        // verbleiben als Restanteil beim Eigentümer. Vorher: 1.500,00 EUR.
        self::assertSame(100000, $ergebnis->statements[0]->allocableTotal->cents);
        self::assertSame(50000, $ergebnis->ownerOverview->residualTotal->cents);
        self::assertTrue($ergebnis->ownerOverview->isBalanced());
        self::assertSame(
            'Direktzuordnung 1.000,00 EUR von 1.500,00 EUR',
            $ergebnis->statements[0]->lines[0]->allocationExplanation
        );
    }

    public function test_leerstandstage_einer_teilbelegten_einheit_bleiben_beim_eigentuemer(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);
        $this->vorauszahlung($lauf, $mandant['tenancy']);

        // Mieter nur 01.01. bis 30.06.2025 (181 Tage), danach kein Nachfolger.
        $mandant['tenancy']->forceFill(['starts_on' => '2025-01-01', 'ends_on' => '2025-06-30'])->save();

        $this->speichern($mandant, $lauf, [
            (string) $mandant['unit']->getKey() => ['heizung' => '1.200,00'],
        ])->assertRedirect();

        // 1.200,00 EUR auf 181 und 184 Tage: exakt 595,07 und 604,93 EUR.
        $zeilen = HeatingStatementLine::query()->get();

        self::assertCount(2, $zeilen);

        $mieter = $zeilen->firstWhere('tenancy_id', (string) $mandant['tenancy']->getKey());
        $leerstand = $zeilen->firstWhere('tenancy_id', null);

        self::assertNotNull($mieter);
        self::assertNotNull($leerstand);
        self::assertSame(59507, (int) $mieter->getAttribute('share_heating_cent'));
        self::assertSame(60493, (int) $leerstand->getAttribute('share_heating_cent'));
        self::assertSame(184, (int) $leerstand->getAttribute('usage_days'));

        /** @var CostItem $position */
        $position = CostItem::query()->where('manual_heating_entry', true)->firstOrFail();

        self::assertSame(120000, (int) $position->getAttribute('amount_cent'));

        /** @var AllocationKeyValue $wert */
        $wert = AllocationKeyValue::query()
            ->whereIn('allocation_key_id', AllocationKey::query()->where('cost_item_id', $position->getKey())->select('id'))
            ->firstOrFail();

        self::assertSame('59507', (string) (int) $wert->getAttribute('numerator'));

        $ergebnis = app(StatementCalculator::class)->calculate(
            app(BillingRunInputAssembler::class)->assemble($lauf->refresh())->input
        );

        self::assertSame(59507, $ergebnis->statements[0]->allocableTotal->cents);
        self::assertSame(60493, $ergebnis->ownerOverview->residualTotal->cents);
        self::assertTrue($ergebnis->ownerOverview->isBalanced());
    }

    public function test_negative_betraege_je_einheit_werden_als_feldfehler_abgelehnt(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $antwort = $this->speichern($mandant, $lauf, [
            (string) $mandant['unit']->getKey() => ['heizung' => '-12,50', 'warmwasser' => '300,00'],
        ]);

        $antwort->assertRedirect();
        $antwort->assertSessionHasErrors(sprintf('einheiten.%s.heizung', $mandant['unit']->getKey()));
        self::assertSame(0, HeatingStatement::query()->count());
        self::assertSame(0, CostItem::query()->where('manual_heating_entry', true)->count());
    }

    public function test_eine_pruefsumme_innerhalb_der_toleranz_blockiert_nicht(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $antwort = $this->speichern($mandant, $lauf, $this->betraege($mandant), ['gesamtbetrag' => '1.400,50']);

        $antwort->assertSessionHas('status');
        self::assertStringNotContainsString(
            'Prüfsumme weicht',
            (string) session('status')
        );

        /** @var HeatingStatement $abrechnung */
        $abrechnung = HeatingStatement::query()->firstOrFail();

        self::assertTrue((bool) $abrechnung->getAttribute('checksum_ok'));
        self::assertSame(-50, (int) $abrechnung->getAttribute('checksum_difference_cent'));
    }

    public function test_eine_pruefsumme_ausserhalb_der_toleranz_blockiert_die_finalisierung(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $antwort = $this->speichern($mandant, $lauf, $this->betraege($mandant), ['gesamtbetrag' => '1.300,00']);

        $antwort->assertSessionHas('status');
        self::assertStringContainsString('Prüfsumme weicht über der Toleranz ab', (string) session('status'));

        /** @var HeatingStatement $abrechnung */
        $abrechnung = HeatingStatement::query()->firstOrFail();

        self::assertFalse((bool) $abrechnung->getAttribute('checksum_ok'));
        self::assertSame(10000, (int) $abrechnung->getAttribute('checksum_difference_cent'));

        $antwort = $this->actingAs($mandant['user'])
            ->get(route('portal.pruefung.heizkosten.erfassung', ['billingRun' => $lauf->getKey()]));

        $antwort->assertSee('Toleranz', false);
    }

    public function test_ohne_gesamtbetrag_entfaellt_die_pruefsumme(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $antwort = $this->speichern($mandant, $lauf, $this->betraege($mandant));

        self::assertStringContainsString('keine Gegenprobe möglich', (string) session('status'));

        /** @var HeatingStatement $abrechnung */
        $abrechnung = HeatingStatement::query()->firstOrFail();

        self::assertNull($abrechnung->getAttribute('checksum_ok'));
        self::assertNull($abrechnung->getAttribute('checksum_difference_cent'));
        self::assertNull($abrechnung->getAttribute('total_cost_cent'));

        $antwort->assertRedirect();
    }

    public function test_unzulaessige_betraege_werden_abgelehnt_und_nichts_gespeichert(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $antwort = $this->speichern($mandant, $lauf, [
            (string) $mandant['unit']->getKey() => ['heizung' => '1.234,567'],
        ]);

        $antwort->assertSessionHasErrors();
        self::assertSame(0, HeatingStatement::query()->count());
    }

    public function test_manuelle_und_externe_betraege_erzeugen_eine_pruefaufgabe_ohne_addition(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);
        $this->externeAbrechnung($lauf);

        $this->speichern($mandant, $lauf, $this->betraege($mandant));

        /** @var ValidationIssue $aufgabe */
        $aufgabe = ValidationIssue::query()->where('rule_code', 'REC-006')->firstOrFail();

        self::assertTrue((bool) $aufgabe->getAttribute('blocks_finalization'));
        self::assertStringContainsString('nicht addiert', (string) $aufgabe->getAttribute('description'));
        self::assertSame(0, CostItem::query()->where('manual_heating_entry', true)->count());
    }

    public function test_eine_weg_summenposition_gilt_ebenfalls_als_konkurrierende_quelle(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);
        $this->wegPositionMitHeizkosten($lauf);

        $this->speichern($mandant, $lauf, $this->betraege($mandant));

        self::assertSame(1, ValidationIssue::query()->where('rule_code', 'REC-006')->count());
        self::assertSame(0, CostItem::query()->where('manual_heating_entry', true)->count());
    }

    public function test_die_entscheidung_fuer_die_manuelle_quelle_setzt_die_betraege_an(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);
        $this->externeAbrechnung($lauf);

        $this->speichern($mandant, $lauf, $this->betraege($mandant), ['quelle' => 'MANUELL']);

        self::assertSame(2, CostItem::query()->where('manual_heating_entry', true)->count());

        /** @var HeatingStatement $abrechnung */
        $abrechnung = HeatingStatement::query()->firstOrFail();

        self::assertSame('MANUELL', $abrechnung->getAttribute('manual_source_decision'));
    }

    public function test_die_entscheidung_fuer_die_externe_quelle_setzt_die_manuellen_betraege_nicht_an(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);
        $this->externeAbrechnung($lauf);

        $this->speichern($mandant, $lauf, $this->betraege($mandant), ['quelle' => 'EXTERN']);

        self::assertSame(0, CostItem::query()->where('manual_heating_entry', true)->count());
        self::assertSame(1, HeatingStatementLine::query()->count());
    }

    public function test_ein_erneutes_speichern_verdoppelt_die_positionen_nicht(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->speichern($mandant, $lauf, $this->betraege($mandant));
        $this->speichern($mandant, $lauf, $this->betraege($mandant));

        self::assertSame(1, HeatingStatement::query()->count());
        self::assertSame(1, HeatingStatementLine::query()->count());
        self::assertSame(2, CostItem::query()->where('manual_heating_entry', true)->count());
    }

    public function test_einheiten_ohne_betraege_erzeugen_keine_zeilen(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        /** @var Unit $zweite */
        $zweite = Unit::factory()->create([
            'organization_id' => $mandant['organization']->getKey(),
            'property_id' => $mandant['property']->getKey(),
            'label' => 'Wohnung 2',
        ]);

        $this->speichern($mandant, $lauf, $this->betraege($mandant));

        self::assertSame(1, HeatingStatementLine::query()->count());
        self::assertSame(
            0,
            HeatingStatementLine::query()->where('unit_id', $zweite->getKey())->count()
        );
    }

    public function test_die_erfassten_betraege_erscheinen_wieder_in_der_maske(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->speichern($mandant, $lauf, $this->betraege($mandant), ['gesamtbetrag' => '1.400,00']);

        $antwort = $this->actingAs($mandant['user'])
            ->get(route('portal.pruefung.heizkosten.erfassung', ['billingRun' => $lauf->getKey()]));

        $antwort->assertOk();
        $antwort->assertSee('1.000,00', false);
        $antwort->assertSee('1.400,00', false);
    }

    public function test_die_matrix_fuehrt_die_quelle_manuell_erfasst_mit_direktzuordnung(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);

        $this->speichern($mandant, $lauf, $this->betraege($mandant));

        $antwort = $this->actingAs($mandant['user'])
            ->get(route('portal.pruefung.heizkosten', ['billingRun' => $lauf->getKey()]));

        $antwort->assertOk();
        $antwort->assertSee('Manuell erfasst, vom Vermieter ermittelt', false);
        $antwort->assertSee('Direktzuordnung', false);
        $antwort->assertSee('Beträge unverändert als Direktzuordnung je Einheit', false);
    }

    public function test_die_matrix_weist_den_quellenkonflikt_aus_und_setzt_nichts_doppelt_an(): void
    {
        $mandant = $this->mandant();
        $lauf = $this->lauf($mandant['organization'], $mandant['property']);
        $this->externeAbrechnung($lauf);

        $this->speichern($mandant, $lauf, $this->betraege($mandant));

        $antwort = $this->actingAs($mandant['user'])
            ->get(route('portal.pruefung.heizkosten', ['billingRun' => $lauf->getKey()]));

        $antwort->assertOk();
        $antwort->assertSee('Die Beträge werden nicht addiert', false);
        $antwort->assertSee('derzeit nicht angesetzt', false);
    }

    private function vorauszahlung(BillingRun $lauf, Tenancy $mietverhaeltnis): void
    {
        Prepayment::query()->create([
            'organization_id' => $lauf->getAttribute('organization_id'),
            'billing_run_id' => $lauf->getKey(),
            'tenancy_id' => $mietverhaeltnis->getKey(),
            'kind' => PrepaymentKind::HEIZKOSTEN,
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'target_cent' => 60000,
            'actual_cent' => 60000,
            'source' => ValueSource::ZAHLUNGSUEBERSICHT,
            'assumed_equal_to_target' => false,
            'confirmed_at' => now(),
        ]);
    }

    /**
     * @param  array{user: User, organization: Organization, property: Property, unit: Unit, tenancy: Tenancy}  $mandant
     * @return array<string, array<string, string>>
     */
    private function betraege(array $mandant): array
    {
        return [
            (string) $mandant['unit']->getKey() => [
                'heizung' => '1.000,00',
                'warmwasser' => '300,00',
                'co2_vermieter' => '40,00',
                'co2_mieter' => '60,00',
                'sonstige' => '0,00',
            ],
        ];
    }

    /**
     * @param  array{user: User, organization: Organization, property: Property, unit: Unit, tenancy: Tenancy}  $mandant
     * @param  array<string, array<string, string>>  $einheiten
     * @param  array<string, string>  $weitere
     * @return TestResponse<Response>
     */
    private function speichern(
        array $mandant,
        BillingRun $lauf,
        array $einheiten,
        array $weitere = [],
    ): TestResponse {
        return $this->actingAs($mandant['user'])->post(
            route('portal.pruefung.heizkosten.speichern', ['billingRun' => $lauf->getKey()]),
            array_merge(['einheiten' => $einheiten], $weitere)
        );
    }
}
