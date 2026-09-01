<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Application\Calculation\CalculateBillingRun;
use App\Application\Wizard\PreviewBuilder;
use App\Application\Wizard\ReviewConfirmation;
use App\Enums\GeneratedDocumentKind;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentVariant;
use App\Enums\LegalDocumentPurpose;
use App\Enums\ValidationIssueStatus;
use App\Enums\ValidationSeverity;
use App\Models\CostItem;
use App\Models\GeneratedDocument;
use App\Models\LegalAcceptance;
use App\Models\Prepayment;
use App\Models\ValidationIssue;
use App\Services\Storage\ArtifactStorage;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Calculation\CalculationTestCase;
use Tests\Feature\Pdf\PdfTextExtractor;

/**
 * Schritt 10 des geführten Ablaufs: Vorschau mit Wasserzeichen und
 * Nutzerbestätigung.
 */
final class PreviewStepTest extends CalculationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_die_vorschau_erzeugt_mieterabrechnungen_und_die_eigentuemeruebersicht(): void
    {
        $szenario = $this->szenario();

        $dokumente = app(PreviewBuilder::class)->rebuild($szenario['billingRun'], $szenario['user']);

        self::assertGreaterThanOrEqual(3, count($dokumente));

        self::assertSame(
            2,
            GeneratedDocument::query()
                ->where('billing_run_id', $szenario['billingRun']->getKey())
                ->where('kind', GeneratedDocumentKind::MIETERABRECHNUNG->value)
                ->count()
        );

        self::assertSame(
            1,
            GeneratedDocument::query()
                ->where('billing_run_id', $szenario['billingRun']->getKey())
                ->where('kind', GeneratedDocumentKind::EIGENTUEMERUEBERSICHT->value)
                ->count()
        );
    }

    public function test_jede_seite_der_vorschau_traegt_ein_wasserzeichen(): void
    {
        $szenario = $this->szenario();

        app(PreviewBuilder::class)->rebuild($szenario['billingRun'], $szenario['user']);

        $dokument = GeneratedDocument::query()
            ->where('billing_run_id', $szenario['billingRun']->getKey())
            ->where('kind', GeneratedDocumentKind::MIETERABRECHNUNG->value)
            ->firstOrFail();

        self::assertSame(GeneratedDocumentVariant::VORSCHAU, $dokument->variant);

        $inhalt = (new ArtifactStorage)->disk()->get($dokument->storage_path);

        self::assertIsString($inhalt);

        $text = PdfTextExtractor::text($inhalt);

        self::assertStringContainsString('VORSCHAU', $text);
        self::assertStringContainsString('Unbezahlte Vorschau', $text);
    }

    public function test_eine_abrechnungsrelevante_aenderung_macht_die_vorschau_ungueltig(): void
    {
        $szenario = $this->szenario();
        $builder = app(PreviewBuilder::class);

        $builder->rebuild($szenario['billingRun'], $szenario['user']);

        self::assertTrue($builder->isValid($szenario['billingRun']->refresh()));

        // Abrechnungsrelevante Änderung: der Betrag der Kostenposition.
        CostItem::query()->whereKey($szenario['costItem']->getKey())->update(['amount_cent' => 240000]);

        app(CalculateBillingRun::class)
            ->handle($szenario['billingRun']->refresh(), $szenario['user']);

        self::assertFalse($builder->isValid($szenario['billingRun']->refresh()));

        $builder->rebuild($szenario['billingRun']->refresh(), $szenario['user']);

        self::assertTrue($builder->isValid($szenario['billingRun']->refresh()));

        self::assertSame(
            2,
            GeneratedDocument::query()
                ->where('billing_run_id', $szenario['billingRun']->getKey())
                ->where('status', GeneratedDocumentStatus::UNGUELTIG->value)
                ->where('kind', GeneratedDocumentKind::MIETERABRECHNUNG->value)
                ->count()
        );
    }

    public function test_das_speichern_der_vorauszahlungen_macht_die_vorschau_ungueltig(): void
    {
        $szenario = $this->szenario();
        $builder = app(PreviewBuilder::class);

        $builder->rebuild($szenario['billingRun'], $szenario['user']);

        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorauszahlungen.speichern', ['billingRun' => $szenario['billingRun']->getKey()]),
            ['zeilen' => [(string) $szenario['tenancies'][0]->getKey() => ['ist' => '100,00']]]
        )->assertRedirect();

        self::assertFalse($builder->isValid($szenario['billingRun']->refresh()));
    }

    public function test_die_seite_zeigt_die_unverbindliche_preisschaetzung(): void
    {
        $szenario = $this->szenario();

        app(PreviewBuilder::class)->rebuild($szenario['billingRun'], $szenario['user']);

        $antwort = $this->actingAs($szenario['user'])->get(
            route('portal.wizard.vorschau', ['billingRun' => $szenario['billingRun']->getKey()])
        );

        $antwort->assertOk();
        $antwort->assertSee('Unverbindliche Preisschätzung');
        $antwort->assertSee('49,80 EUR');
        $antwort->assertSee('Unverbindliche Schätzung.');
        $antwort->assertSee('keine absolute Kopiersperre');
    }

    public function test_die_bestaetigungscheckbox_ist_nicht_vorangekreuzt(): void
    {
        $szenario = $this->szenario();

        app(PreviewBuilder::class)->rebuild($szenario['billingRun'], $szenario['user']);

        $antwort = $this->actingAs($szenario['user'])->get(
            route('portal.wizard.vorschau', ['billingRun' => $szenario['billingRun']->getKey()])
        );

        $antwort->assertOk();
        $antwort->assertSee('name="bestaetigung"', false);
        $antwort->assertDontSee('name="bestaetigung" value="1" class="mt-1" checked', false);
        $antwort->assertDontSee('checked', false);
    }

    public function test_die_bestaetigung_wird_in_legal_acceptances_protokolliert(): void
    {
        $szenario = $this->szenario();

        app(PreviewBuilder::class)->rebuild($szenario['billingRun'], $szenario['user']);

        $antwort = $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorschau.bestaetigen', ['billingRun' => $szenario['billingRun']->getKey()]),
            ['bestaetigung' => '1'],
            ['HTTP_USER_AGENT' => 'Beispielbrowser 1.0']
        );

        $antwort->assertRedirect();

        $nachweis = LegalAcceptance::query()
            ->where('billing_run_id', $szenario['billingRun']->getKey())
            ->firstOrFail();

        self::assertSame(LegalDocumentPurpose::ABRECHNUNGSVERANTWORTUNG, $nachweis->purpose);
        self::assertSame(ReviewConfirmation::TEXT_VERSION, $nachweis->document_version);
        self::assertSame(hash('sha256', ReviewConfirmation::TEXT), $nachweis->document_hash);
        self::assertNotNull($nachweis->accepted_at);
        self::assertSame($szenario['user']->getKey(), $nachweis->user_id);

        // Datensparsamkeit: gekürzte IP und gehashter User-Agent.
        self::assertNotSame('Beispielbrowser 1.0', $nachweis->user_agent_hash);
        self::assertSame(hash('sha256', 'Beispielbrowser 1.0'), $nachweis->user_agent_hash);

        $lauf = $szenario['billingRun']->refresh();

        self::assertNotNull($lauf->review_confirmed_at);
        self::assertNotNull($lauf->responsibility_confirmed_at);
    }

    public function test_ohne_bestaetigung_gibt_es_keinen_checkout(): void
    {
        $szenario = $this->szenario();

        app(PreviewBuilder::class)->rebuild($szenario['billingRun'], $szenario['user']);

        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorschau.bestaetigen', ['billingRun' => $szenario['billingRun']->getKey()]),
            []
        )->assertSessionHasErrors('bestaetigung');

        self::assertSame(0, LegalAcceptance::query()->count());
        self::assertNull($szenario['billingRun']->refresh()->review_confirmed_at);
    }

    public function test_ohne_gueltige_vorschau_ist_keine_bestaetigung_moeglich(): void
    {
        $szenario = $this->szenario();

        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorschau.bestaetigen', ['billingRun' => $szenario['billingRun']->getKey()]),
            ['bestaetigung' => '1']
        )->assertSessionHasErrors('bestaetigung');

        self::assertSame(0, LegalAcceptance::query()->count());
    }

    public function test_ein_blocker_verhindert_die_erzeugung_der_vorschau(): void
    {
        $szenario = $this->szenario();

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

        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorschau.erzeugen', ['billingRun' => $szenario['billingRun']->getKey()])
        )->assertSessionHasErrors('vorschau');

        self::assertFalse(app(PreviewBuilder::class)->isValid($szenario['billingRun']->refresh()));
    }

    public function test_eine_neue_bestaetigung_wird_nach_einer_neuen_vorschau_erneut_verlangt(): void
    {
        $szenario = $this->szenario();
        $builder = app(PreviewBuilder::class);

        $builder->rebuild($szenario['billingRun'], $szenario['user']);

        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorschau.bestaetigen', ['billingRun' => $szenario['billingRun']->getKey()]),
            ['bestaetigung' => '1']
        )->assertRedirect();

        self::assertNotNull($szenario['billingRun']->refresh()->review_confirmed_at);

        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorschau.erzeugen', ['billingRun' => $szenario['billingRun']->getKey()])
        )->assertRedirect();

        self::assertNull($szenario['billingRun']->refresh()->review_confirmed_at);
        // Der Protokolleintrag bleibt bestehen.
        self::assertSame(1, LegalAcceptance::query()->count());
    }

    public function test_ein_unvollstaendiger_lauf_zeigt_die_ursache_statt_einer_technischen_meldung(): void
    {
        $szenario = $this->szenario();

        Prepayment::query()
            ->where('billing_run_id', $szenario['billingRun']->getKey())
            ->delete();

        $antwort = $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorschau.erzeugen', ['billingRun' => $szenario['billingRun']->getKey()])
        );

        $antwort->assertSessionHasErrors('vorschau');

        $fehler = session('errors')?->get('vorschau') ?? [];

        self::assertStringContainsString('Schritt 7 ist ein Pflichtschritt', implode(' ', $fehler));
    }

    public function test_mandantentrennung_der_route(): void
    {
        $szenario = $this->szenario();
        $fremder = $this->mandant();

        $this->actingAs($fremder['user'])->get(
            route('portal.wizard.vorschau', ['billingRun' => $szenario['billingRun']->getKey()])
        )->assertForbidden();

        $this->actingAs($fremder['user'])->post(
            route('portal.wizard.vorschau.erzeugen', ['billingRun' => $szenario['billingRun']->getKey()])
        )->assertForbidden();

        $this->actingAs($fremder['user'])->post(
            route('portal.wizard.vorschau.bestaetigen', ['billingRun' => $szenario['billingRun']->getKey()]),
            ['bestaetigung' => '1']
        )->assertForbidden();
    }
}
