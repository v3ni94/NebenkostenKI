<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Application\Wizard\PrepaymentWorkspace;
use App\Enums\ValueSource;
use App\Models\AuditLog;
use App\Models\Prepayment;
use Tests\Feature\Calculation\CalculationTestCase;

/**
 * Schritt 7 des geführten Ablaufs: Vorauszahlungen.
 */
final class PrepaymentStepTest extends CalculationTestCase
{
    public function test_die_maske_zeigt_soll_ist_und_herkunft(): void
    {
        $szenario = $this->szenario();

        $antwort = $this->actingAs($szenario['user'])->get(
            route('portal.wizard.vorauszahlungen', ['billingRun' => $szenario['billingRun']->getKey()])
        );

        $antwort->assertOk();
        $antwort->assertSee('Schritt 7: Vorauszahlungen');
        $antwort->assertSee('Sollsumme');
        $antwort->assertSee('Tatsächlich geleistet');
        $antwort->assertSee('Herkunft');
        $antwort->assertSee('Zahlungsübersicht');
        $antwort->assertSee('Wohnung A');
    }

    public function test_die_sollsumme_wird_taggenau_aus_dem_monatsbetrag_berechnet(): void
    {
        $szenario = $this->szenario();

        Prepayment::query()->where('billing_run_id', $szenario['billingRun']->getKey())->delete();

        $zeilen = app(PrepaymentWorkspace::class)->rows($szenario['billingRun']->refresh());

        // 150,00 EUR Betriebskosten plus 90,00 EUR Heizkosten mal zwölf Monate.
        self::assertSame(288000, $zeilen[0]->targetTotal->cents);
        self::assertStringContainsString('240,00 EUR monatlich × 12 × 365 Nutzungstage', $zeilen[0]->targetExplanation);
    }

    public function test_die_annahme_ist_gleich_soll_ist_nicht_vorangekreuzt(): void
    {
        $szenario = $this->szenario();

        $zeilen = app(PrepaymentWorkspace::class)->rows($szenario['billingRun']);

        self::assertFalse($zeilen[0]->assumedFromTarget);
    }

    public function test_ist_werte_werden_gespeichert(): void
    {
        $szenario = $this->szenario();

        $antwort = $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorauszahlungen.speichern', ['billingRun' => $szenario['billingRun']->getKey()]),
            [
                'zeilen' => [
                    (string) $szenario['tenancies'][0]->getKey() => [
                        'ist' => '2.500,50',
                        'herkunft' => ValueSource::ZAHLUNGSUEBERSICHT->value,
                    ],
                ],
            ]
        );

        $antwort->assertRedirect();

        $vorauszahlung = Prepayment::query()
            ->where('tenancy_id', $szenario['tenancies'][0]->getKey())
            ->firstOrFail();

        self::assertSame(250050, $vorauszahlung->actual_cent);
        self::assertFalse($vorauszahlung->assumed_equal_to_target);
        self::assertSame(ValueSource::ZAHLUNGSUEBERSICHT, $vorauszahlung->source);
    }

    public function test_die_bestaetigte_annahme_wird_protokolliert(): void
    {
        $szenario = $this->szenario();

        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorauszahlungen.speichern', ['billingRun' => $szenario['billingRun']->getKey()]),
            [
                'zeilen' => [
                    (string) $szenario['tenancies'][0]->getKey() => ['annahme' => '1'],
                ],
            ]
        )->assertRedirect();

        $vorauszahlung = Prepayment::query()
            ->where('tenancy_id', $szenario['tenancies'][0]->getKey())
            ->firstOrFail();

        self::assertTrue($vorauszahlung->assumed_equal_to_target);
        self::assertSame(ValueSource::SOLL_ANNAHME, $vorauszahlung->source);
        self::assertNotNull($vorauszahlung->confirmed_at);
        self::assertSame($szenario['user']->getKey(), $vorauszahlung->confirmed_by_user_id);

        self::assertSame(
            1,
            AuditLog::query()->where('action', PrepaymentWorkspace::AUDIT_ASSUMPTION)->count()
        );
    }

    public function test_offene_zeilen_verhindern_das_weitergehen(): void
    {
        $szenario = $this->szenario();

        Prepayment::query()->where('billing_run_id', $szenario['billingRun']->getKey())->delete();

        $antwort = $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorauszahlungen.weiter', ['billingRun' => $szenario['billingRun']->getKey()])
        );

        $antwort->assertRedirect();
        $antwort->assertSessionHasErrors('weiter');
        self::assertFalse(app(PrepaymentWorkspace::class)->isComplete($szenario['billingRun']->refresh()));
    }

    public function test_vollstaendige_zeilen_erlauben_das_weitergehen(): void
    {
        $szenario = $this->szenario();

        $antwort = $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorauszahlungen.weiter', ['billingRun' => $szenario['billingRun']->getKey()])
        );

        $antwort->assertRedirect(
            route('portal.wizard.schluessel', ['billingRun' => $szenario['billingRun']->getKey()])
        );
        self::assertSame(8, $szenario['billingRun']->refresh()->wizard_step);
    }

    public function test_abweichung_zwischen_ist_und_soll_wird_ausgewiesen(): void
    {
        $szenario = $this->szenario();

        Prepayment::query()
            ->where('billing_run_id', $szenario['billingRun']->getKey())
            ->update(['actual_cent' => 250000]);

        $zeilen = app(PrepaymentWorkspace::class)->rows($szenario['billingRun']->refresh());

        self::assertTrue($zeilen[0]->hasDeviation());
        self::assertSame(-38000, $zeilen[0]->deviation()?->cents);
    }

    public function test_mandantentrennung_der_route(): void
    {
        $szenario = $this->szenario();
        $fremder = $this->mandant();

        $this->actingAs($fremder['user'])->get(
            route('portal.wizard.vorauszahlungen', ['billingRun' => $szenario['billingRun']->getKey()])
        )->assertForbidden();

        $this->actingAs($fremder['user'])->post(
            route('portal.wizard.vorauszahlungen.speichern', ['billingRun' => $szenario['billingRun']->getKey()]),
            ['zeilen' => [(string) $szenario['tenancies'][0]->getKey() => ['ist' => '10,00']]]
        )->assertForbidden();

        $this->actingAs($fremder['user'])->post(
            route('portal.wizard.vorauszahlungen.weiter', ['billingRun' => $szenario['billingRun']->getKey()])
        )->assertForbidden();
    }
}
