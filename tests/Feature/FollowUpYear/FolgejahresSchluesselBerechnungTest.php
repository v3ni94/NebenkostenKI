<?php

declare(strict_types=1);

namespace Tests\Feature\FollowUpYear;

use App\Application\Calculation\BillingRunInputAssembler;
use App\Application\Reminder\ReminderLinks;
use App\Application\Wizard\PreviewBuilder;
use App\Enums\AllocationKeySource;
use App\Enums\AllocationKeyType;
use App\Enums\BillingRunStatus;
use App\Enums\CostItemStatus;
use App\Enums\PrepaymentKind;
use App\Enums\ValueSource;
use App\Models\AllocationKey;
use App\Models\BillingRun;
use App\Models\CalculationSnapshot;
use App\Models\CostItem;
use App\Models\Prepayment;
use App\Models\Tenancy;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Calculation\CalculationTestCase;

/**
 * Folgejahresuebernahme bis zur Vorschau.
 *
 * Ein aus dem Vorjahr uebernommener Schluessel der Bezugsebene
 * Nutzungszeitraum (Verbrauch) kommt ohne Zaehler in den neuen Lauf. Nach dem
 * Speichern in Schritt 8 darf dieser Vorjahresdatensatz die Berechnung nicht
 * mehr verhindern: Vorher baute der Assembler jeden Schluessel des Laufs und
 * brach an dem wertlosen Vorjahresschluessel ab, das Folgejahr war dauerhaft
 * nicht berechenbar.
 */
final class FolgejahresSchluesselBerechnungTest extends CalculationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * @return array{szenario: array<string, mixed>, neu: BillingRun}
     */
    private function folgejahrMitVorjahresVerbrauchsschluessel(): array
    {
        $szenario = $this->szenario();

        // Vorjahr 2025: bestaetigter Verbrauchsschluessel je Mietverhaeltnis,
        // finalisiert.
        $szenario['key']->forceFill([
            'key_type' => AllocationKeyType::VERBRAUCH,
            'source' => AllocationKeySource::MANUELL,
            'measurement_unit' => 'm3',
            'denominator' => null,
        ])->save();

        $this->schluesselwert($szenario['key'], '80.000', null, $szenario['tenancies'][0]);
        $this->schluesselwert($szenario['key'], '40.000', null, $szenario['tenancies'][1]);

        $szenario['billingRun']->forceFill([
            'status' => BillingRunStatus::FINALIZED,
            'finalized_at' => now(),
        ])->save();

        // Folgejahresuebernahme ueber den Anwendungsweg (CTA aus der Erinnerung).
        $this->actingAs($szenario['user'])
            ->get(app(ReminderLinks::class)->folgejahrUrl($szenario['property'], 2026))
            ->assertRedirect();

        /** @var BillingRun $neu */
        $neu = BillingRun::query()
            ->where('property_id', $szenario['property']->getKey())
            ->where('billing_year', 2026)
            ->firstOrFail();

        // Kosten und Vorauszahlungen des neuen Jahres.
        CostItem::factory()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'billing_run_id' => $neu->getKey(),
            'cost_category_id' => $szenario['category']->getKey(),
            'description' => 'Gebäudereinigung Treppenhaus 2026',
            'amount_cent' => 150000,
            'status' => CostItemStatus::BESTAETIGT,
            'confirmed_at' => now(),
        ]);

        foreach ($szenario['tenancies'] as $mietverhaeltnis) {
            self::assertInstanceOf(Tenancy::class, $mietverhaeltnis);

            Prepayment::query()->create([
                'organization_id' => $neu->getAttribute('organization_id'),
                'billing_run_id' => $neu->getKey(),
                'tenancy_id' => $mietverhaeltnis->getKey(),
                'kind' => PrepaymentKind::BETRIEBSKOSTEN,
                'period_start' => '2026-01-01',
                'period_end' => '2026-12-31',
                'target_cent' => 288000,
                'actual_cent' => 288000,
                'source' => ValueSource::ZAHLUNGSUEBERSICHT,
                'assumed_equal_to_target' => false,
                'confirmed_at' => now(),
            ]);
        }

        return ['szenario' => $szenario, 'neu' => $neu->refresh()];
    }

    public function test_nach_dem_speichern_in_schritt_8_ist_das_folgejahr_berechenbar(): void
    {
        ['szenario' => $szenario, 'neu' => $neu] = $this->folgejahrMitVorjahresVerbrauchsschluessel();

        /** @var AllocationKey $vorjahresschluessel */
        $vorjahresschluessel = AllocationKey::query()
            ->where('billing_run_id', $neu->getKey())
            ->where('source', AllocationKeySource::VORJAHR->value)
            ->firstOrFail();

        self::assertSame(AllocationKeyType::VERBRAUCH, $vorjahresschluessel->key_type);
        self::assertCount(0, $vorjahresschluessel->values);

        // Schritt 8: der Nutzer waehlt Wohnflaeche und speichert.
        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.schluessel.speichern', ['billingRun' => $neu->getKey()]),
            [
                'kostenarten' => [
                    (string) $szenario['category']->getKey() => [
                        'key_type' => AllocationKeyType::WOHNFLAECHE->value,
                        'nenner' => '150,00',
                        'werte' => [
                            (string) $szenario['units'][0]->getKey() => '100,00',
                            (string) $szenario['units'][1]->getKey() => '50,00',
                        ],
                    ],
                ],
            ]
        )->assertRedirect()->assertSessionDoesntHaveErrors();

        // Der ersetzte Vorjahresschluessel besteht nicht weiter.
        self::assertFalse(AllocationKey::query()->whereKey($vorjahresschluessel->getKey())->exists());
        self::assertSame(
            1,
            AllocationKey::query()
                ->where('billing_run_id', $neu->getKey())
                ->where('cost_category_id', $szenario['category']->getKey())
                ->count()
        );

        // Schritt 10: die Vorschau wird erzeugt, die Berechnung laeuft.
        $antwort = $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorschau.erzeugen', ['billingRun' => $neu->getKey()])
        );

        $antwort->assertRedirect();
        $antwort->assertSessionDoesntHaveErrors('vorschau');

        self::assertTrue(app(PreviewBuilder::class)->isValid($neu->refresh()));
        self::assertSame(1, CalculationSnapshot::query()->where('billing_run_id', $neu->getKey())->count());
    }

    public function test_der_assembler_baut_nur_den_massgeblichen_schluessel_je_kostenart(): void
    {
        ['szenario' => $szenario, 'neu' => $neu] = $this->folgejahrMitVorjahresVerbrauchsschluessel();

        // Altbestand vor dieser Behebung: ein manueller Schluessel neben dem
        // wertlosen Vorjahresdatensatz derselben Kostenart.
        $manuell = $this->schluessel(
            $neu,
            $szenario['category'],
            AllocationKeyType::WOHNFLAECHE,
            AllocationKeySource::MANUELL,
            '150.000000'
        );

        $aufbau = app(BillingRunInputAssembler::class)->assemble($neu->refresh());

        self::assertCount(1, $aufbau->input->allocationKeys);
        self::assertArrayHasKey(
            BillingRunInputAssembler::ALLOCATION_KEY_PREFIX.$manuell->getKey(),
            $aufbau->input->allocationKeys
        );
        self::assertSame(
            AllocationKeyType::WOHNFLAECHE->value,
            $aufbau->allocationKeyTypeByRef[$aufbau->input->costItems[0]->allocationKeyRef] ?? null
        );
    }
}
