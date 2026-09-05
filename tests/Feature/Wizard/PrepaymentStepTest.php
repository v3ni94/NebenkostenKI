<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Application\Wizard\PrepaymentWorkspace;
use App\Enums\PrepaymentKind;
use App\Enums\ValueSource;
use App\Models\AuditLog;
use App\Models\Prepayment;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $antwort->assertSee('Schritt 7 von 12');
        $antwort->assertSee('Vorauszahlungen');
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
        self::assertStringContainsString(
            '240,00 EUR monatlich für 12 volle Monate im Nutzungszeitraum (365 Nutzungstage) ergeben 2.880,00 EUR.',
            $zeilen[0]->targetExplanation
        );
    }

    /**
     * B3: Ein unterjähriger Abrechnungszeitraum ergibt nur die Monatsraten
     * seiner Monate, nicht den Jahresbetrag.
     */
    public function test_die_sollsumme_bei_unterjaehrigem_zeitraum_entspricht_den_faelligen_monatsraten(): void
    {
        $szenario = $this->szenario();

        Prepayment::query()->where('billing_run_id', $szenario['billingRun']->getKey())->delete();
        $szenario['billingRun']->forceFill(['period_start' => '2025-01-01', 'period_end' => '2025-06-30'])->save();

        $zeilen = app(PrepaymentWorkspace::class)->rows($szenario['billingRun']->refresh());

        // 240,00 EUR monatlich mal sechs Monate, nicht mal zwölf.
        self::assertSame(144000, $zeilen[0]->targetTotal->cents);
        self::assertStringContainsString('für 6 volle Monate im Nutzungszeitraum (181 Nutzungstage)', $zeilen[0]->targetExplanation);
    }

    /**
     * B3: Ein angebrochener Monat zählt taggenau innerhalb dieses Monats.
     */
    public function test_ein_angebrochener_monat_zaehlt_taggenau(): void
    {
        $szenario = $this->szenario();

        Prepayment::query()->where('billing_run_id', $szenario['billingRun']->getKey())->delete();
        $szenario['tenancies'][0]->forceFill(['starts_on' => '2025-01-16'])->save();

        $zeilen = app(PrepaymentWorkspace::class)->rows($szenario['billingRun']->refresh());

        // 11 volle Monate plus 16 von 31 Tagen im Januar: 240 × (11 + 16/31) = 2.763,87 EUR.
        self::assertSame(276387, $zeilen[0]->targetTotal->cents);
        self::assertStringContainsString('11 volle Monate und einen anteiligen Monat (16 von 31 Tagen im Monat 01/2025)', $zeilen[0]->targetExplanation);
    }

    /**
     * U7: Nach Änderung des Monatsbetrags gilt eine bestätigte Annahme Ist
     * gleich Soll nicht mehr. Die Zeile ist offen und der Sollwert aktuell.
     */
    public function test_eine_bestaetigte_annahme_verfaellt_bei_geaendertem_monatsbetrag(): void
    {
        $szenario = $this->szenario();
        $mietverhaeltnis = $szenario['tenancies'][0];

        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorauszahlungen.speichern', ['billingRun' => $szenario['billingRun']->getKey()]),
            ['zeilen' => [(string) $mietverhaeltnis->getKey() => ['annahme' => '1']]]
        )->assertRedirect();

        $this->actingAs($szenario['user'])->put(
            route('portal.mietverhaeltnisse.update', ['tenancy' => $mietverhaeltnis->getKey()]),
            [
                'tenant_display_name' => $mietverhaeltnis->tenant_display_name,
                'kind' => 'WOHNRAUM',
                'starts_on' => '2025-01-01',
                'heating_prepayment_separate' => '1',
                'monthly_operating_prepayment_eur' => '200,00',
                'monthly_heating_prepayment_eur' => '90,00',
            ]
        )->assertRedirect();

        $zeilen = app(PrepaymentWorkspace::class)->rows($szenario['billingRun']->refresh());

        self::assertSame(348000, $zeilen[0]->targetTotal->cents);
        self::assertStringContainsString('290,00 EUR monatlich', $zeilen[0]->targetExplanation);
        self::assertStringContainsString('ergeben 3.480,00 EUR.', $zeilen[0]->targetExplanation);
        self::assertFalse($zeilen[0]->assumedFromTarget);
        self::assertTrue($zeilen[0]->isOpen());
        self::assertFalse(app(PrepaymentWorkspace::class)->isComplete($szenario['billingRun']->refresh()));

        // Befund N10: Die Entwertung wirkt nicht nur in der Anzeige. Die
        // Datenbankzeile ist offen, der alte Ist-Wert ist verworfen und der
        // Sollwert aktuell; die Bereinigung ist protokolliert.
        $gespeichert = Prepayment::query()->where('tenancy_id', $mietverhaeltnis->getKey())->firstOrFail();

        self::assertFalse($gespeichert->assumed_equal_to_target);
        self::assertNull($gespeichert->actual_cent);
        self::assertNull($gespeichert->confirmed_at);
        self::assertSame(348000, $gespeichert->target_cent);
        self::assertTrue(
            AuditLog::query()->where('action', PrepaymentWorkspace::AUDIT_TARGET_REFRESHED)->exists()
        );

        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorauszahlungen.speichern', ['billingRun' => $szenario['billingRun']->getKey()]),
            ['zeilen' => [(string) $mietverhaeltnis->getKey() => ['annahme' => '1']]]
        )->assertRedirect();

        $vorauszahlung = Prepayment::query()->where('tenancy_id', $mietverhaeltnis->getKey())->firstOrFail();

        self::assertSame(348000, $vorauszahlung->target_cent);
        self::assertSame(348000, $vorauszahlung->actual_cent);
    }

    /**
     * U7: Ein erfasster Ist-Wert bleibt bestehen, der gespeicherte Sollwert
     * wird aus den aktuellen Vertragsdaten neu abgeleitet. Die Ableitung
     * geschieht auf dem Aenderungsweg (Bearbeitung des Mietverhaeltnisses),
     * nicht beim Lesen der Maske (Befund N10).
     */
    public function test_ein_gespeicherter_sollwert_wird_bei_geaendertem_monatsbetrag_neu_abgeleitet(): void
    {
        $szenario = $this->szenario();
        $mietverhaeltnis = $szenario['tenancies'][0];

        $this->actingAs($szenario['user'])->put(
            route('portal.mietverhaeltnisse.update', ['tenancy' => $mietverhaeltnis->getKey()]),
            [
                'tenant_display_name' => $mietverhaeltnis->tenant_display_name,
                'kind' => 'WOHNRAUM',
                'starts_on' => '2025-01-01',
                'heating_prepayment_separate' => '1',
                'monthly_operating_prepayment_eur' => '200,00',
                'monthly_heating_prepayment_eur' => '90,00',
            ]
        )->assertRedirect();

        $vorauszahlung = Prepayment::query()->where('tenancy_id', $mietverhaeltnis->getKey())->firstOrFail();

        self::assertSame(348000, $vorauszahlung->target_cent);
        self::assertSame(288000, $vorauszahlung->actual_cent);

        $zeilen = app(PrepaymentWorkspace::class)->rows($szenario['billingRun']->refresh());

        self::assertSame(348000, $zeilen[0]->targetTotal->cents);
        self::assertSame(288000, $zeilen[0]->actualTotal?->cents);
        self::assertFalse($zeilen[0]->isOpen());
    }

    /**
     * Der Lesepfad schreibt nichts: Ein direkt in der Datenbank veralteter
     * Sollwert bleibt beim Aufbau der Maske unveraendert, die Anzeige nennt
     * dennoch den aktuellen Sollwert (Befund N10).
     */
    public function test_der_aufbau_der_maske_schreibt_keine_sollwerte(): void
    {
        $szenario = $this->szenario();
        $mietverhaeltnis = $szenario['tenancies'][0];

        $mietverhaeltnis->forceFill(['monthly_operating_prepayment_cent' => 20000])->save();

        $zeilen = app(PrepaymentWorkspace::class)->rows($szenario['billingRun']->refresh());

        self::assertSame(348000, $zeilen[0]->targetTotal->cents);

        $vorauszahlung = Prepayment::query()->where('tenancy_id', $mietverhaeltnis->getKey())->firstOrFail();

        self::assertSame(288000, $vorauszahlung->target_cent);
        self::assertSame(288000, $vorauszahlung->actual_cent);
        self::assertFalse(
            AuditLog::query()->where('action', PrepaymentWorkspace::AUDIT_TARGET_REFRESHED)->exists()
        );
    }

    /**
     * Mehrere gespeicherte Zeilen eines Mietverhaeltnisses: Der neue Sollwert
     * wird je Kostenart abgeleitet, die Ist-Werte je Zeile bleiben erhalten.
     */
    public function test_getrennte_zeilen_je_kostenart_erhalten_ihren_sollanteil(): void
    {
        $szenario = $this->szenario();
        $mietverhaeltnis = $szenario['tenancies'][0];

        Prepayment::query()->where('tenancy_id', $mietverhaeltnis->getKey())->update([
            'kind' => PrepaymentKind::BETRIEBSKOSTEN->value,
            'target_cent' => 180000,
            'actual_cent' => 180000,
        ]);

        Prepayment::query()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'billing_run_id' => $szenario['billingRun']->getKey(),
            'tenancy_id' => $mietverhaeltnis->getKey(),
            'kind' => PrepaymentKind::HEIZKOSTEN,
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'target_cent' => 108000,
            'actual_cent' => 100000,
            'source' => ValueSource::ZAHLUNGSUEBERSICHT,
            'assumed_equal_to_target' => false,
            'confirmed_at' => now(),
        ]);

        $this->actingAs($szenario['user'])->put(
            route('portal.mietverhaeltnisse.update', ['tenancy' => $mietverhaeltnis->getKey()]),
            [
                'tenant_display_name' => $mietverhaeltnis->tenant_display_name,
                'kind' => 'WOHNRAUM',
                'starts_on' => '2025-01-01',
                'heating_prepayment_separate' => '1',
                'monthly_operating_prepayment_eur' => '200,00',
                'monthly_heating_prepayment_eur' => '100,00',
            ]
        )->assertRedirect();

        $betrieb = Prepayment::query()
            ->where('tenancy_id', $mietverhaeltnis->getKey())
            ->where('kind', PrepaymentKind::BETRIEBSKOSTEN->value)
            ->firstOrFail();
        $heizung = Prepayment::query()
            ->where('tenancy_id', $mietverhaeltnis->getKey())
            ->where('kind', PrepaymentKind::HEIZKOSTEN->value)
            ->firstOrFail();

        self::assertSame(240000, $betrieb->target_cent);
        self::assertSame(180000, $betrieb->actual_cent);
        self::assertSame(120000, $heizung->target_cent);
        self::assertSame(100000, $heizung->actual_cent);

        $zeilen = app(PrepaymentWorkspace::class)->rows($szenario['billingRun']->refresh());

        self::assertSame(360000, $zeilen[0]->targetTotal->cents);
        self::assertSame(280000, $zeilen[0]->actualTotal?->cents);
    }

    /**
     * Befund R6: Traegt nur eine von zwei Zeilen eine Annahme Ist gleich Soll,
     * wird nur diese Zeile wieder geoeffnet. Die Zeile mit erfasstem Ist-Wert
     * behaelt ihn und erhaelt lediglich den neuen Sollanteil.
     */
    public function test_nur_annahmezeilen_werden_bei_geaendertem_monatsbetrag_wieder_geoeffnet(): void
    {
        $szenario = $this->szenario();
        $mietverhaeltnis = $szenario['tenancies'][0];

        Prepayment::query()->where('tenancy_id', $mietverhaeltnis->getKey())->update([
            'kind' => PrepaymentKind::BETRIEBSKOSTEN->value,
            'target_cent' => 180000,
            'actual_cent' => 180000,
            'source' => ValueSource::SOLL_ANNAHME->value,
            'assumed_equal_to_target' => true,
        ]);

        Prepayment::query()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'billing_run_id' => $szenario['billingRun']->getKey(),
            'tenancy_id' => $mietverhaeltnis->getKey(),
            'kind' => PrepaymentKind::HEIZKOSTEN,
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'target_cent' => 108000,
            'actual_cent' => 100000,
            'source' => ValueSource::ZAHLUNGSUEBERSICHT,
            'assumed_equal_to_target' => false,
            'confirmed_at' => now(),
        ]);

        $this->actingAs($szenario['user'])->put(
            route('portal.mietverhaeltnisse.update', ['tenancy' => $mietverhaeltnis->getKey()]),
            [
                'tenant_display_name' => $mietverhaeltnis->tenant_display_name,
                'kind' => 'WOHNRAUM',
                'starts_on' => '2025-01-01',
                'heating_prepayment_separate' => '1',
                'monthly_operating_prepayment_eur' => '200,00',
                'monthly_heating_prepayment_eur' => '100,00',
            ]
        )->assertRedirect();

        $betrieb = Prepayment::query()
            ->where('tenancy_id', $mietverhaeltnis->getKey())
            ->where('kind', PrepaymentKind::BETRIEBSKOSTEN->value)
            ->firstOrFail();
        $heizung = Prepayment::query()
            ->where('tenancy_id', $mietverhaeltnis->getKey())
            ->where('kind', PrepaymentKind::HEIZKOSTEN->value)
            ->firstOrFail();

        // Die Annahmezeile ist offen.
        self::assertSame(240000, $betrieb->target_cent);
        self::assertNull($betrieb->actual_cent);
        self::assertFalse($betrieb->assumed_equal_to_target);
        self::assertNull($betrieb->confirmed_at);

        // Der erfasste Ist-Wert der Heizkostenzeile bleibt bestehen.
        self::assertSame(120000, $heizung->target_cent);
        self::assertSame(100000, $heizung->actual_cent);
        self::assertSame(ValueSource::ZAHLUNGSUEBERSICHT, $heizung->source);
        self::assertNotNull($heizung->confirmed_at);
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

    /**
     * B11, B30: Der Punkt ist nur bei genau drei Folgeziffern ein
     * Tausendertrennzeichen, sonst Dezimaltrennzeichen. Ein Suffix EUR ist
     * zulässig.
     *
     * @return array<string, array{string, int}>
     */
    public static function istBetraege(): array
    {
        return [
            'deutsche Schreibweise' => ['2.500,50', 250050],
            'Punkt als Dezimaltrennzeichen' => ['1500.00', 150000],
            'Punkt als Dezimaltrennzeichen mit vier Vorkommastellen' => ['1234.56', 123456],
            'Punkt als Dezimaltrennzeichen mit zwei Vorkommastellen' => ['12.50', 1250],
            'Punkt als Tausendertrennzeichen' => ['1.500', 150000],
            'mit Suffix EUR' => ['1.500,00 EUR', 150000],
            'Tausenderpunkt mit Suffix EUR' => ['1.200 EUR', 120000],
        ];
    }

    #[DataProvider('istBetraege')]
    public function test_ist_werte_werden_in_jeder_zulaessigen_schreibweise_exakt_umgerechnet(string $eingabe, int $erwartetCent): void
    {
        $szenario = $this->szenario();

        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorauszahlungen.speichern', ['billingRun' => $szenario['billingRun']->getKey()]),
            ['zeilen' => [(string) $szenario['tenancies'][0]->getKey() => ['ist' => $eingabe]]]
        )->assertRedirect()->assertSessionHasNoErrors();

        $vorauszahlung = Prepayment::query()
            ->where('tenancy_id', $szenario['tenancies'][0]->getKey())
            ->firstOrFail();

        self::assertSame($erwartetCent, $vorauszahlung->actual_cent);
    }

    /**
     * B11, B30: Nicht auswertbare Beträge werden abgelehnt, nicht gerundet und
     * nicht still verworfen.
     *
     * @return array<string, array{string}>
     */
    public static function unzulaessigeIstBetraege(): array
    {
        return [
            'drei Nachkommastellen' => ['12,345'],
            'englische Tausenderschreibweise' => ['1,200.50'],
            'Text' => ['etwa dreihundert'],
        ];
    }

    #[DataProvider('unzulaessigeIstBetraege')]
    public function test_ein_nicht_auswertbarer_ist_wert_wird_mit_fehlermeldung_abgelehnt(string $eingabe): void
    {
        $szenario = $this->szenario();
        $tenancyId = (string) $szenario['tenancies'][0]->getKey();

        $antwort = $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorauszahlungen.speichern', ['billingRun' => $szenario['billingRun']->getKey()]),
            ['zeilen' => [$tenancyId => ['ist' => $eingabe]]]
        );

        $antwort->assertSessionHasErrors('zeilen.'.$tenancyId.'.ist');
        self::assertStringContainsString(
            '1.234,56',
            (string) session('errors')?->first('zeilen.'.$tenancyId.'.ist')
        );

        $vorauszahlung = Prepayment::query()->where('tenancy_id', $tenancyId)->firstOrFail();

        self::assertSame(288000, $vorauszahlung->actual_cent);
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
