<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Application\Calculation\BillingRunInputAssembler;
use App\Application\Wizard\AllocationKeyWorkspace;
use App\Domain\Allocation\ConsumptionKey;
use App\Domain\Calculation\StatementCalculator;
use App\Enums\AllocationKeySource;
use App\Enums\AllocationKeyType;
use App\Enums\BillingMode;
use App\Enums\CostItemStatus;
use App\Enums\HeatingSupplyCase;
use App\Models\AllocationKey;
use App\Models\AllocationKeyValue;
use App\Models\BillingRun;
use App\Models\CostItem;
use App\Models\ManualOverride;
use App\Models\Tenancy;
use App\Models\Unit;
use App\Models\VacancyPeriod;
use Tests\Feature\Calculation\CalculationTestCase;

/**
 * Schritt 8 des geführten Ablaufs: Verteilerschlüssel und Verbrauch.
 */
final class AllocationKeyStepTest extends CalculationTestCase
{
    public function test_bestaetigte_mietvertragsregelung_hat_vorrang_und_traegt_den_quellenbadge(): void
    {
        $szenario = $this->szenario();

        $zeilen = app(AllocationKeyWorkspace::class)->rows($szenario['billingRun']);

        self::assertCount(1, $zeilen);
        self::assertSame(AllocationKeyType::WOHNFLAECHE, $zeilen[0]->keyType);
        self::assertSame(AllocationKeySource::MIETVERTRAG, $zeilen[0]->source);
        self::assertSame('Mietvertrag', $zeilen[0]->sourceBadge());
    }

    public function test_ohne_mietvertragsregelung_wird_der_bestaetigte_vorjahresschluessel_vorgeschlagen(): void
    {
        $szenario = $this->szenario();

        $szenario['key']->delete();

        /** @var BillingRun $vorjahr */
        $vorjahr = BillingRun::factory()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'property_id' => $szenario['property']->getKey(),
            'period_start' => '2024-01-01',
            'period_end' => '2024-12-31',
            'billing_year' => 2024,
            'mode' => BillingMode::FULL_PROPERTY,
        ]);

        AllocationKey::factory()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'billing_run_id' => $vorjahr->getKey(),
            'cost_category_id' => $szenario['category']->getKey(),
            'key_type' => AllocationKeyType::EINHEITEN,
            'source' => AllocationKeySource::MANUELL,
            'denominator' => null,
            'confirmed_at' => now(),
        ]);

        $szenario['billingRun']->forceFill(['previous_billing_run_id' => $vorjahr->getKey()])->save();

        $zeilen = app(AllocationKeyWorkspace::class)->rows($szenario['billingRun']->refresh());

        self::assertSame(AllocationKeyType::EINHEITEN, $zeilen[0]->keyType);
        self::assertSame(AllocationKeySource::VORJAHR, $zeilen[0]->source);
        self::assertSame('Aus Vorjahr übernommen', $zeilen[0]->sourceBadge());
    }

    public function test_ohne_bestaetigten_schluessel_wird_der_standardwert_mit_warnhinweis_vorgeschlagen(): void
    {
        $szenario = $this->szenario();

        $szenario['key']->delete();

        $zeilen = app(AllocationKeyWorkspace::class)->rows($szenario['billingRun']->refresh());

        self::assertSame(AllocationKeySource::DEFAULT, $zeilen[0]->source);
        self::assertSame('Fachlicher Standardwert', $zeilen[0]->sourceBadge());
        self::assertStringContainsString('Bitte prüfen Sie die Mietvertragsregelung', (string) $zeilen[0]->defaultWarning);
    }

    public function test_anteilssumme_von_100_prozent_ist_vollstaendig(): void
    {
        $szenario = $this->szenario();

        $zeilen = app(AllocationKeyWorkspace::class)->rows($szenario['billingRun']);

        self::assertSame('100,00', $zeilen[0]->sharePercent);
        self::assertTrue($zeilen[0]->isComplete());
        self::assertNull($zeilen[0]->shareWarning());
        self::assertSame([], app(AllocationKeyWorkspace::class)->blockingReasons($szenario['billingRun']));
    }

    public function test_anteilssumme_ungleich_100_prozent_blockiert(): void
    {
        $szenario = $this->szenario();

        $szenario['key']->forceFill(['denominator' => '300.000000'])->save();

        $arbeitsflaeche = app(AllocationKeyWorkspace::class);
        $zeilen = $arbeitsflaeche->rows($szenario['billingRun']->refresh());

        self::assertSame('50,00', $zeilen[0]->sharePercent);
        self::assertFalse($zeilen[0]->isComplete());
        self::assertStringContainsString('nicht 100,00 Prozent', (string) $zeilen[0]->shareWarning());
        self::assertNotSame([], $arbeitsflaeche->blockingReasons($szenario['billingRun']));

        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.schluessel.weiter', ['billingRun' => $szenario['billingRun']->getKey()])
        )->assertSessionHasErrors('weiter');
    }

    public function test_fehlende_werte_je_einheit_blockieren_und_werden_im_text_benannt(): void
    {
        $szenario = $this->szenario();

        Unit::query()->whereKey($szenario['units'][1]->getKey())->update(['living_area_sqm' => null]);

        $arbeitsflaeche = app(AllocationKeyWorkspace::class);
        $zeilen = $arbeitsflaeche->rows($szenario['billingRun']->refresh());

        self::assertTrue($zeilen[0]->hasMissingValues());
        self::assertStringContainsString('Wohnung B', (string) $zeilen[0]->missingValues()[0]->missingText());
        self::assertNotSame([], $arbeitsflaeche->blockingReasons($szenario['billingRun']));

        $antwort = $this->actingAs($szenario['user'])->get(
            route('portal.wizard.schluessel', ['billingRun' => $szenario['billingRun']->getKey()])
        );

        $antwort->assertOk();
        $antwort->assertSee('Blockiert die Abrechnung');
        $antwort->assertSee('border-status-error', false);
    }

    public function test_abweichung_von_der_mietvertragsregelung_warnt_ohne_zu_blockieren(): void
    {
        $szenario = $this->szenario();

        // Zusätzlich ein manuell gewählter, abweichender Schlüssel.
        AllocationKey::factory()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'billing_run_id' => $szenario['billingRun']->getKey(),
            'cost_category_id' => $szenario['category']->getKey(),
            'key_type' => AllocationKeyType::EINHEITEN,
            'source' => AllocationKeySource::MANUELL,
            'denominator' => null,
            'confirmed_at' => now(),
        ]);

        $arbeitsflaeche = app(AllocationKeyWorkspace::class);
        $zeilen = $arbeitsflaeche->rows($szenario['billingRun']->refresh());

        self::assertTrue($zeilen[0]->deviatesFromContract());
        self::assertStringContainsString(
            'weicht von der bestätigten Mietvertragsregelung',
            (string) $zeilen[0]->deviationWarning()
        );
        self::assertNotSame([], $arbeitsflaeche->warnings($szenario['billingRun']));
        self::assertSame([], $arbeitsflaeche->blockingReasons($szenario['billingRun']));
    }

    public function test_alle_schluesseltypen_stehen_zur_auswahl(): void
    {
        $typen = AllocationKeyWorkspace::selectableTypes();

        self::assertContains(AllocationKeyType::BEHEIZTE_WOHNFLAECHE, $typen);
        self::assertContains(AllocationKeyType::PERSONENTAGE, $typen);
        self::assertContains(AllocationKeyType::INDIVIDUELL_1, $typen);
        self::assertContains(AllocationKeyType::INDIVIDUELL_5, $typen);
        self::assertCount(13, $typen);
    }

    public function test_die_maske_zeigt_werte_nenner_und_rechenweg(): void
    {
        $szenario = $this->szenario();

        $antwort = $this->actingAs($szenario['user'])->get(
            route('portal.wizard.schluessel', ['billingRun' => $szenario['billingRun']->getKey()])
        );

        $antwort->assertOk();
        $antwort->assertSee('Schritt 8: Verteilerschlüssel und Verbrauch');
        $antwort->assertSee('Summe der Anteile: 100,00 Prozent');
        $antwort->assertSee('Nenner: 150,00');
        $antwort->assertSee('Quelle: Mietvertrag');
        $antwort->assertSee('nicht dasselbe');
    }

    public function test_schluessel_und_werte_werden_gespeichert(): void
    {
        $szenario = $this->szenario();

        $antwort = $this->actingAs($szenario['user'])->post(
            route('portal.wizard.schluessel.speichern', ['billingRun' => $szenario['billingRun']->getKey()]),
            [
                'kostenarten' => [
                    (string) $szenario['category']->getKey() => [
                        'key_type' => AllocationKeyType::BEHEIZTE_WOHNFLAECHE->value,
                        'nenner' => '150,00',
                        'werte' => [
                            (string) $szenario['units'][0]->getKey() => '100,00',
                            (string) $szenario['units'][1]->getKey() => '50,00',
                        ],
                    ],
                ],
            ]
        );

        $antwort->assertRedirect();

        $gespeichert = AllocationKey::query()
            ->where('billing_run_id', $szenario['billingRun']->getKey())
            ->where('source', AllocationKeySource::MANUELL->value)
            ->firstOrFail();

        self::assertSame(AllocationKeyType::BEHEIZTE_WOHNFLAECHE, $gespeichert->key_type);
        self::assertSame('150.000000', $gespeichert->denominator);
        self::assertCount(2, $gespeichert->values);
        self::assertSame('100.000000', $gespeichert->values[0]->numerator);
    }

    public function test_verbrauch_ohne_zwischenablesung_verlangt_eine_bestaetigung(): void
    {
        $szenario = $this->verbrauchMitNutzerwechsel();

        $arbeitsflaeche = app(AllocationKeyWorkspace::class);

        self::assertCount(1, $arbeitsflaeche->unitsNeedingSubstituteConfirmation($szenario['billingRun']));

        $gruende = $arbeitsflaeche->blockingReasons($szenario['billingRun']);

        self::assertNotSame([], $gruende);
        self::assertStringContainsString('keine Zwischenablesung', implode(' ', $gruende));
    }

    public function test_die_bestaetigte_ersatzverteilung_wird_protokolliert(): void
    {
        $szenario = $this->verbrauchMitNutzerwechsel();

        $antwort = $this->actingAs($szenario['user'])->post(
            route('portal.wizard.schluessel.ersatzverteilung', [
                'billingRun' => $szenario['billingRun']->getKey(),
                'unit' => $szenario['units'][0]->getKey(),
            ])
        );

        $antwort->assertRedirect();

        $eintrag = ManualOverride::query()
            ->where('billing_run_id', $szenario['billingRun']->getKey())
            ->where('field', BillingRunInputAssembler::SUBSTITUTE_FIELD)
            ->firstOrFail();

        self::assertSame((string) $szenario['units'][0]->getKey(), $eintrag->subject_id);
        self::assertStringContainsString('ausdrücklich bestätigt', (string) $eintrag->reason);
        self::assertSame(
            [],
            app(AllocationKeyWorkspace::class)->unitsNeedingSubstituteConfirmation($szenario['billingRun']->refresh())
        );
    }

    public function test_heizkostenfall_c_blendet_die_heizkostenkategorie_aus(): void
    {
        $szenario = $this->szenario();
        $heizung = $this->kategorie('HEIZUNG');

        CostItem::factory()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'billing_run_id' => $szenario['billingRun']->getKey(),
            'cost_category_id' => $heizung->getKey(),
            'amount_cent' => 400000,
            'is_heating_cost' => true,
            'status' => CostItemStatus::BESTAETIGT,
        ]);

        $szenario['billingRun']->forceFill(['heating_supply_case' => HeatingSupplyCase::DEZENTRAL])->save();

        $zeilen = app(AllocationKeyWorkspace::class)->rows($szenario['billingRun']->refresh());

        self::assertCount(1, $zeilen);
        self::assertSame($szenario['category']->name, $zeilen[0]->categoryLabel);
    }

    public function test_mandantentrennung_der_route(): void
    {
        $szenario = $this->szenario();
        $fremder = $this->mandant();

        $this->actingAs($fremder['user'])->get(
            route('portal.wizard.schluessel', ['billingRun' => $szenario['billingRun']->getKey()])
        )->assertForbidden();

        $this->actingAs($fremder['user'])->post(
            route('portal.wizard.schluessel.speichern', ['billingRun' => $szenario['billingRun']->getKey()]),
            [
                'kostenarten' => [
                    (string) $szenario['category']->getKey() => [
                        'key_type' => AllocationKeyType::WOHNFLAECHE->value,
                    ],
                ],
            ]
        )->assertForbidden();

        $this->actingAs($fremder['user'])->post(
            route('portal.wizard.schluessel.weiter', ['billingRun' => $szenario['billingRun']->getKey()])
        )->assertForbidden();

        $this->actingAs($fremder['user'])->post(
            route('portal.wizard.schluessel.ersatzverteilung', [
                'billingRun' => $szenario['billingRun']->getKey(),
                'unit' => $szenario['units'][0]->getKey(),
            ])
        )->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function verbrauchMitNutzerwechsel(): array
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

        // Jahresverbrauch je Einheit, ohne Zwischenablesung beim Nutzerwechsel
        // in Wohnung A.
        $this->schluesselwert($szenario['key'], '120.000', $szenario['units'][0]);
        $this->schluesselwert($szenario['key'], '30.000', $szenario['units'][1]);

        $szenario['tenancies'][] = $nachmieter;
        $szenario['billingRun'] = $szenario['billingRun']->refresh();

        return $szenario;
    }

    public function test_vollstaendige_zwischenablesungen_brauchen_keine_ersatzverteilung(): void
    {
        $szenario = $this->verbrauchMitNutzerwechsel();

        $this->schluesselwert($szenario['key'], '61.000', null, $szenario['tenancies'][0]);
        $this->schluesselwert($szenario['key'], '59.000', null, $szenario['tenancies'][2]);

        $arbeitsflaeche = app(AllocationKeyWorkspace::class);
        $lauf = $szenario['billingRun']->refresh();

        self::assertSame([], $arbeitsflaeche->unitsNeedingSubstituteConfirmation($lauf));
        self::assertSame([], $arbeitsflaeche->blockingReasons($lauf));

        $schluessel = array_values(app(BillingRunInputAssembler::class)->assemble($lauf)->input->allocationKeys)[0];

        self::assertInstanceOf(ConsumptionKey::class, $schluessel);
        self::assertSame(0, $schluessel->numeratorFor((string) $szenario['tenancies'][0]->getKey())->compareTo('61'));
        self::assertSame(0, $schluessel->numeratorFor((string) $szenario['tenancies'][2]->getKey())->compareTo('59'));
        self::assertSame([], $schluessel->substituteParticipants());
    }

    public function test_jahresverbrauch_je_einheit_wird_ueber_die_maske_gespeichert_und_ersatzweise_verteilt(): void
    {
        $szenario = $this->verbrauchMitNutzerwechsel();

        $antwort = $this->actingAs($szenario['user'])->post(
            route('portal.wizard.schluessel.speichern', ['billingRun' => $szenario['billingRun']->getKey()]),
            [
                'kostenarten' => [
                    (string) $szenario['category']->getKey() => [
                        'key_type' => AllocationKeyType::VERBRAUCH->value,
                        'masseinheit' => 'm3',
                        'werte' => [
                            (string) $szenario['units'][0]->getKey() => '100,000',
                            (string) $szenario['units'][1]->getKey() => '50,000',
                            // Zwischenablesungen bleiben leer.
                            (string) $szenario['tenancies'][0]->getKey() => '',
                            (string) $szenario['tenancies'][2]->getKey() => '',
                        ],
                    ],
                ],
            ]
        );

        $antwort->assertRedirect();
        $antwort->assertSessionDoesntHaveErrors();

        $gespeichert = AllocationKey::query()
            ->where('billing_run_id', $szenario['billingRun']->getKey())
            ->where('source', AllocationKeySource::MANUELL->value)
            ->where('key_type', AllocationKeyType::VERBRAUCH->value)
            ->firstOrFail();

        // Vorher wurden Werte der Bezugsebene Nutzungszeitraum ausschließlich
        // je Mietverhältnis gespeichert; ein Einheitenwert war unmöglich.
        self::assertCount(2, $gespeichert->values);
        self::assertSame((string) $szenario['units'][0]->getKey(), $gespeichert->values[0]->unit_id);
        self::assertNull($gespeichert->values[0]->tenancy_id);

        $arbeitsflaeche = app(AllocationKeyWorkspace::class);
        $lauf = $szenario['billingRun']->refresh();

        self::assertCount(1, $arbeitsflaeche->unitsNeedingSubstituteConfirmation($lauf));
        self::assertStringContainsString('keine Zwischenablesung', implode(' ', $arbeitsflaeche->blockingReasons($lauf)));

        $arbeitsflaeche->confirmSubstituteDistribution($lauf, (string) $szenario['units'][0]->getKey(), $szenario['user']);

        self::assertSame([], $arbeitsflaeche->blockingReasons($lauf->refresh()));

        $schluessel = array_values(app(BillingRunInputAssembler::class)->assemble($lauf->refresh())->input->allocationKeys)[0];

        // 100,000 m³ auf 181 und 184 Tage: 49,589 und 50,411 m³, gekennzeichnet.
        self::assertInstanceOf(ConsumptionKey::class, $schluessel);
        self::assertSame('49.589', (string) $schluessel->numeratorFor((string) $szenario['tenancies'][0]->getKey()));
        self::assertSame('50.411', (string) $schluessel->numeratorFor((string) $szenario['tenancies'][2]->getKey()));
        self::assertCount(2, $schluessel->substituteParticipants());
    }

    public function test_unvollstaendige_zwischenablesungen_werden_mit_bestaetigter_ersatzverteilung_ergaenzt(): void
    {
        $szenario = $this->verbrauchMitNutzerwechsel();

        // Nur die Vormieterin hat eine Zwischenablesung, der Nachmieter nicht.
        $this->schluesselwert($szenario['key'], '61.000', null, $szenario['tenancies'][0]);

        $arbeitsflaeche = app(AllocationKeyWorkspace::class);
        $lauf = $szenario['billingRun']->refresh();

        self::assertCount(1, $arbeitsflaeche->unitsNeedingSubstituteConfirmation($lauf));

        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.schluessel.ersatzverteilung', [
                'billingRun' => $lauf->getKey(),
                'unit' => $szenario['units'][0]->getKey(),
            ])
        )->assertRedirect();

        self::assertSame([], $arbeitsflaeche->blockingReasons($lauf->refresh()));

        // Vorher brach der Aufbau trotz bestaetigter Ersatzverteilung ab.
        $schluessel = array_values(app(BillingRunInputAssembler::class)->assemble($lauf->refresh())->input->allocationKeys)[0];

        // 120,000 m³ Jahreswert: 61,000 abgelesen, der Rest 59,000 geht an den
        // Nachmieter und wird als Ersatzverteilung gekennzeichnet.
        self::assertInstanceOf(ConsumptionKey::class, $schluessel);
        self::assertSame(0, $schluessel->numeratorFor((string) $szenario['tenancies'][0]->getKey())->compareTo('61'));
        self::assertSame(0, $schluessel->numeratorFor((string) $szenario['tenancies'][2]->getKey())->compareTo('59'));
        // Nenner: 61 + 59 m³ der Wohnung A und 30 m³ der Wohnung B.
        self::assertSame(0, $schluessel->denominator()->compareTo('150'));
        self::assertSame([(string) $szenario['tenancies'][2]->getKey()], $schluessel->substituteParticipants());
    }

    public function test_direktzuordnung_in_schritt_8_nimmt_betraege_in_euro_entgegen(): void
    {
        $szenario = $this->szenario();

        // 1.200,00 EUR Gebaeudereinigung: 300,50 EUR an A, 899,50 EUR an B.
        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.schluessel.speichern', ['billingRun' => $szenario['billingRun']->getKey()]),
            [
                'kostenarten' => [
                    (string) $szenario['category']->getKey() => [
                        'key_type' => AllocationKeyType::DIREKT->value,
                        'werte' => [
                            (string) $szenario['tenancies'][0]->getKey() => '300,50',
                            (string) $szenario['tenancies'][1]->getKey() => '899,50',
                        ],
                    ],
                ],
            ]
        )->assertRedirect()->assertSessionDoesntHaveErrors();

        $gespeichert = AllocationKey::query()
            ->where('billing_run_id', $szenario['billingRun']->getKey())
            ->where('source', AllocationKeySource::MANUELL->value)
            ->firstOrFail();

        // Gespeichert wird in Cent, angezeigt in Euro.
        self::assertSame('30050', (string) (int) $gespeichert->values->firstWhere('tenancy_id', (string) $szenario['tenancies'][0]->getKey())?->numerator);

        $zeilen = app(AllocationKeyWorkspace::class)->rows($szenario['billingRun']->refresh());

        self::assertSame(AllocationKeyType::DIREKT, $zeilen[0]->keyType);
        self::assertSame('300,50', $zeilen[0]->values[0]->value);
        self::assertSame('899,50', $zeilen[0]->values[1]->value);

        // Vorher: 300,50 wurde als Cent gelesen, amountFor() scheiterte an der
        // Rundung, die Berechnung brach ab.
        $ergebnis = app(StatementCalculator::class)->calculate(
            app(BillingRunInputAssembler::class)->assemble($szenario['billingRun']->refresh())->input
        );

        self::assertSame(30050, $ergebnis->statements[0]->allocableTotal->cents);
        self::assertSame(89950, $ergebnis->statements[1]->allocableTotal->cents);
        self::assertSame(0, $ergebnis->ownerOverview->residualTotal->cents);
        self::assertTrue($ergebnis->ownerOverview->isBalanced());
    }

    public function test_ein_unzulaessiger_eurobetrag_der_direktzuordnung_wird_abgewiesen(): void
    {
        $szenario = $this->szenario();

        $antwort = $this->actingAs($szenario['user'])->post(
            route('portal.wizard.schluessel.speichern', ['billingRun' => $szenario['billingRun']->getKey()]),
            [
                'kostenarten' => [
                    (string) $szenario['category']->getKey() => [
                        'key_type' => AllocationKeyType::DIREKT->value,
                        'werte' => [
                            (string) $szenario['tenancies'][0]->getKey() => '300,505',
                        ],
                    ],
                ],
            ]
        );

        $antwort->assertRedirect();
        $antwort->assertSessionHasErrors(sprintf(
            'kostenarten.%s.werte.%s',
            $szenario['category']->getKey(),
            $szenario['tenancies'][0]->getKey()
        ));

        self::assertSame(
            0,
            AllocationKey::query()
                ->where('billing_run_id', $szenario['billingRun']->getKey())
                ->where('source', AllocationKeySource::MANUELL->value)
                ->count()
        );
    }

    public function test_leerstand_zaehlt_als_nutzerwechsel_und_kann_bestaetigt_werden(): void
    {
        $szenario = $this->szenario();

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

        $this->schluesselwert($szenario['key'], '100.000', $szenario['units'][0]);
        $this->schluesselwert($szenario['key'], '30.000', $szenario['units'][1]);

        $arbeitsflaeche = app(AllocationKeyWorkspace::class);
        $lauf = $szenario['billingRun']->refresh();

        // Vorher zählte nur mehr als ein Mietverhältnis; die Einheit wurde
        // nie zur Bestätigung angeboten, die Berechnung brach aber ab.
        self::assertCount(1, $arbeitsflaeche->unitsNeedingSubstituteConfirmation($lauf));

        $arbeitsflaeche->confirmSubstituteDistribution($lauf, (string) $szenario['units'][0]->getKey(), $szenario['user']);

        $schluessel = array_values(app(BillingRunInputAssembler::class)->assemble($lauf->refresh())->input->allocationKeys)[0];

        // 100,000 m³ auf 243 Tage Mieter und 122 Tage Leerstand: 66,575 und
        // 33,425 m³; der Leerstandsanteil verbleibt beim Eigentümer.
        self::assertInstanceOf(ConsumptionKey::class, $schluessel);
        self::assertSame('66.575', (string) $schluessel->numeratorFor((string) $szenario['tenancies'][0]->getKey()));
        self::assertSame(0, $schluessel->denominator()->compareTo('130'));
    }

    public function test_fremde_oder_unbekannte_beteiligte_werden_nicht_gespeichert(): void
    {
        $szenario = $this->szenario();
        $fremder = $this->mandant();

        foreach ([(string) $fremder['unit']->getKey(), '01JUNBEKANNT0000000000000A'] as $kennung) {
            $antwort = $this->actingAs($szenario['user'])->post(
                route('portal.wizard.schluessel.speichern', ['billingRun' => $szenario['billingRun']->getKey()]),
                [
                    'kostenarten' => [
                        (string) $szenario['category']->getKey() => [
                            'key_type' => AllocationKeyType::WOHNFLAECHE->value,
                            'werte' => [
                                (string) $szenario['units'][0]->getKey() => '100,00',
                                (string) $szenario['units'][1]->getKey() => '50,00',
                                $kennung => '12,50',
                            ],
                        ],
                    ],
                ]
            );

            $antwort->assertRedirect();
            $antwort->assertSessionHasErrors(sprintf('kostenarten.%s.werte.%s', $szenario['category']->getKey(), $kennung));
        }

        self::assertSame(
            0,
            AllocationKey::query()
                ->where('billing_run_id', $szenario['billingRun']->getKey())
                ->where('source', AllocationKeySource::MANUELL->value)
                ->count()
        );
        self::assertSame(0, AllocationKeyValue::query()->where('unit_id', $fremder['unit']->getKey())->count());
    }

    public function test_werte_einer_anderen_bezugsebene_werden_beim_typwechsel_nicht_uebernommen(): void
    {
        $szenario = $this->szenario();

        // Das Formular sendet die Werte je Einheit weiter, obwohl der Typ auf
        // Personentage (je Mietverhältnis) gewechselt wurde. Vorher wurden die
        // Einheitenkennungen als Mietverhältnis gespeichert.
        $antwort = $this->actingAs($szenario['user'])->post(
            route('portal.wizard.schluessel.speichern', ['billingRun' => $szenario['billingRun']->getKey()]),
            [
                'kostenarten' => [
                    (string) $szenario['category']->getKey() => [
                        'key_type' => AllocationKeyType::PERSONENTAGE->value,
                        'werte' => [
                            (string) $szenario['units'][0]->getKey() => '100,00',
                            (string) $szenario['units'][1]->getKey() => '50,00',
                        ],
                    ],
                ],
            ]
        );

        $antwort->assertRedirect();
        $antwort->assertSessionDoesntHaveErrors();

        $gespeichert = AllocationKey::query()
            ->where('billing_run_id', $szenario['billingRun']->getKey())
            ->where('source', AllocationKeySource::MANUELL->value)
            ->firstOrFail();

        self::assertSame(AllocationKeyType::PERSONENTAGE, $gespeichert->key_type);
        self::assertCount(0, $gespeichert->values);
    }

    public function test_unbestaetigter_vorjahresschluessel_blockiert_bis_zum_speichern(): void
    {
        $szenario = $this->szenario();

        $szenario['key']->forceFill([
            'source' => AllocationKeySource::VORJAHR,
            'confirmed_at' => null,
            'confirmed_by_user_id' => null,
        ])->save();

        $arbeitsflaeche = app(AllocationKeyWorkspace::class);
        $gruende = $arbeitsflaeche->blockingReasons($szenario['billingRun']->refresh());

        self::assertNotSame([], $gruende);
        self::assertStringContainsString('aus dem Vorjahr übernommen und noch nicht bestätigt', implode(' ', $gruende));

        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.schluessel.weiter', ['billingRun' => $szenario['billingRun']->getKey()])
        )->assertSessionHasErrors('weiter');

        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.schluessel.speichern', ['billingRun' => $szenario['billingRun']->getKey()]),
            [
                'kostenarten' => [
                    (string) $szenario['category']->getKey() => [
                        'key_type' => AllocationKeyType::WOHNFLAECHE->value,
                        'werte' => [
                            (string) $szenario['units'][0]->getKey() => '100,00',
                            (string) $szenario['units'][1]->getKey() => '50,00',
                        ],
                    ],
                ],
            ]
        )->assertRedirect();

        self::assertSame([], $arbeitsflaeche->blockingReasons($szenario['billingRun']->refresh()));
    }
}
