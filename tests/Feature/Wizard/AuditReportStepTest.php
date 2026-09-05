<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Application\Wizard\AuditReportPresenter;
use App\Enums\ValidationIssueStatus;
use App\Enums\ValidationSeverity;
use App\Models\ValidationIssue;
use Tests\Feature\Calculation\CalculationTestCase;

/**
 * Schritt 9 des geführten Ablaufs: Prüfbericht in vier Gruppen.
 */
final class AuditReportStepTest extends CalculationTestCase
{
    public function test_der_bericht_wird_in_vier_gruppen_dargestellt(): void
    {
        $szenario = $this->szenario();

        $antwort = $this->actingAs($szenario['user'])->get(
            route('portal.wizard.pruefbericht', ['billingRun' => $szenario['billingRun']->getKey()])
        );

        $antwort->assertOk();
        $antwort->assertSee('Schritt 9 von 12');
        $antwort->assertSee('Prüfbericht');
        $antwort->assertSee('Blockiert die Abrechnung');
        $antwort->assertSee('Warnung');
        $antwort->assertSee('Hinweis');
        $antwort->assertSee('Bestanden');
        $antwort->assertSee('Angewendeter Regelstand');
    }

    public function test_die_gruppen_enthalten_alle_vier_stufen(): void
    {
        $szenario = $this->szenario();
        $presenter = app(AuditReportPresenter::class);

        $presenter->run($szenario['billingRun']);
        $gruppen = $presenter->groups($szenario['billingRun']);

        foreach (ValidationSeverity::cases() as $stufe) {
            self::assertArrayHasKey($stufe->value, $gruppen);
        }

        self::assertNotSame([], $gruppen[ValidationSeverity::BESTANDEN->value]);
    }

    public function test_ein_blocker_verhindert_das_weitergehen_zur_vorschau(): void
    {
        $szenario = $this->szenario();
        $presenter = app(AuditReportPresenter::class);

        $presenter->run($szenario['billingRun']);

        ValidationIssue::factory()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'billing_run_id' => $szenario['billingRun']->getKey(),
            'rule_code' => 'TEST-BLOCKER',
            'rule_version' => '1.0.0',
            'severity' => ValidationSeverity::BLOCKER,
            'status' => ValidationIssueStatus::OFFEN,
            'blocks_finalization' => true,
            'title' => 'Beispielblocker',
            'description' => 'Diese Angabe ist zu korrigieren.',
            'detected_at' => now(),
        ]);

        self::assertFalse($presenter->mayProceed($szenario['billingRun']));

        $antwort = $this->actingAs($szenario['user'])->post(
            route('portal.wizard.pruefbericht.weiter', ['billingRun' => $szenario['billingRun']->getKey()])
        );

        $antwort->assertSessionHasErrors('weiter');
    }

    public function test_ohne_blocker_geht_es_weiter_zur_vorschau(): void
    {
        $szenario = $this->szenario();

        ValidationIssue::query()
            ->where('billing_run_id', $szenario['billingRun']->getKey())
            ->where('severity', ValidationSeverity::BLOCKER->value)
            ->delete();

        $antwort = $this->actingAs($szenario['user'])->post(
            route('portal.wizard.pruefbericht.weiter', ['billingRun' => $szenario['billingRun']->getKey()])
        );

        $antwort->assertRedirect(
            route('portal.wizard.vorschau', ['billingRun' => $szenario['billingRun']->getKey()])
        );
        self::assertSame(10, $szenario['billingRun']->refresh()->wizard_step);
    }

    public function test_eine_warnung_wird_nur_mit_protokollierter_entscheidung_aufgeloest(): void
    {
        $szenario = $this->szenario();

        /** @var ValidationIssue $warnung */
        $warnung = ValidationIssue::factory()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'billing_run_id' => $szenario['billingRun']->getKey(),
            'rule_code' => 'TEST-WARNUNG',
            'rule_version' => '1.0.0',
            'severity' => ValidationSeverity::WARNUNG,
            'status' => ValidationIssueStatus::OFFEN,
            'blocks_finalization' => false,
            'title' => 'Beispielwarnung',
            'description' => 'Bitte prüfen Sie diese Angabe.',
            'detected_at' => now(),
        ]);

        $antwort = $this->actingAs($szenario['user'])->post(
            route('portal.wizard.pruefbericht.entscheiden', [
                'billingRun' => $szenario['billingRun']->getKey(),
                'issue' => $warnung->getKey(),
            ]),
            ['entscheidung' => 'Der Wert ist geprüft und richtig.']
        );

        $antwort->assertRedirect();

        $aktualisiert = $warnung->refresh();

        self::assertSame(ValidationIssueStatus::AKZEPTIERT, $aktualisiert->status);
        self::assertSame('Der Wert ist geprüft und richtig.', $aktualisiert->resolution);
        self::assertSame($szenario['user']->getKey(), $aktualisiert->resolved_by_user_id);
        self::assertNotNull($aktualisiert->resolved_at);
    }

    public function test_eine_entscheidung_ohne_text_wird_abgewiesen(): void
    {
        $szenario = $this->szenario();

        /** @var ValidationIssue $warnung */
        $warnung = ValidationIssue::factory()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'billing_run_id' => $szenario['billingRun']->getKey(),
            'rule_code' => 'TEST-WARNUNG',
            'rule_version' => '1.0.0',
            'severity' => ValidationSeverity::WARNUNG,
            'status' => ValidationIssueStatus::OFFEN,
            'title' => 'Beispielwarnung',
            'description' => 'Bitte prüfen Sie diese Angabe.',
            'detected_at' => now(),
        ]);

        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.pruefbericht.entscheiden', [
                'billingRun' => $szenario['billingRun']->getKey(),
                'issue' => $warnung->getKey(),
            ]),
            ['entscheidung' => '']
        )->assertSessionHasErrors('entscheidung');

        self::assertSame(ValidationIssueStatus::OFFEN, $warnung->refresh()->status);
    }

    public function test_ein_blocker_ist_nicht_durch_eine_entscheidung_aufloesbar(): void
    {
        $szenario = $this->szenario();

        /** @var ValidationIssue $blocker */
        $blocker = ValidationIssue::factory()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'billing_run_id' => $szenario['billingRun']->getKey(),
            'rule_code' => 'TEST-BLOCKER',
            'rule_version' => '1.0.0',
            'severity' => ValidationSeverity::BLOCKER,
            'status' => ValidationIssueStatus::OFFEN,
            'blocks_finalization' => true,
            'title' => 'Beispielblocker',
            'description' => 'Diese Angabe ist zu korrigieren.',
            'detected_at' => now(),
        ]);

        $antwort = $this->actingAs($szenario['user'])->post(
            route('portal.wizard.pruefbericht.entscheiden', [
                'billingRun' => $szenario['billingRun']->getKey(),
                'issue' => $blocker->getKey(),
            ]),
            ['entscheidung' => 'Ich möchte trotzdem fortfahren.']
        );

        $antwort->assertSessionHasErrors('entscheidung');
        self::assertSame(ValidationIssueStatus::OFFEN, $blocker->refresh()->status);
    }

    public function test_eine_fremde_pruefaufgabe_ist_nicht_auffindbar(): void
    {
        $szenario = $this->szenario();
        $fremder = $this->mandant();

        /** @var ValidationIssue $fremdeAufgabe */
        $fremdeAufgabe = ValidationIssue::factory()->create([
            'organization_id' => $fremder['organization']->getKey(),
            'billing_run_id' => $szenario['billingRun']->getKey(),
            'rule_code' => 'TEST-WARNUNG',
            'rule_version' => '1.0.0',
            'severity' => ValidationSeverity::WARNUNG,
            'status' => ValidationIssueStatus::OFFEN,
            'title' => 'Fremde Aufgabe',
            'description' => 'Nicht sichtbar.',
            'detected_at' => now(),
        ]);

        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.pruefbericht.entscheiden', [
                'billingRun' => $szenario['billingRun']->getKey(),
                'issue' => $fremdeAufgabe->getKey(),
            ]),
            ['entscheidung' => 'Der Wert ist geprüft.']
        )->assertNotFound();
    }

    public function test_mandantentrennung_der_route(): void
    {
        $szenario = $this->szenario();
        $fremder = $this->mandant();

        $this->actingAs($fremder['user'])->get(
            route('portal.wizard.pruefbericht', ['billingRun' => $szenario['billingRun']->getKey()])
        )->assertForbidden();

        $this->actingAs($fremder['user'])->post(
            route('portal.wizard.pruefbericht.weiter', ['billingRun' => $szenario['billingRun']->getKey()])
        )->assertForbidden();
    }
}
