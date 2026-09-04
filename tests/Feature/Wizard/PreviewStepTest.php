<?php

declare(strict_types=1);

namespace Tests\Feature\Wizard;

use App\Application\Wizard\PreviewBuilder;
use App\Application\Wizard\ReviewConfirmation;
use App\Enums\BillingMode;
use App\Enums\CostItemStatus;
use App\Enums\GeneratedDocumentKind;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentVariant;
use App\Enums\LegalDocumentPurpose;
use App\Enums\TenancyKind;
use App\Enums\ValidationIssueStatus;
use App\Enums\ValidationSeverity;
use App\Mail\VorschauBereitMail;
use App\Models\BillingRun;
use App\Models\CalculationSnapshot;
use App\Models\CostItem;
use App\Models\EmailMessage;
use App\Models\GeneratedDocument;
use App\Models\LegalAcceptance;
use App\Models\Prepayment;
use App\Models\Tenancy;
use App\Models\User;
use App\Models\ValidationIssue;
use App\Services\Storage\ArtifactStorage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
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

    /**
     * Anwendungsweg der Kostenprüfung: PUT pruefung.kosten.update. Der Test
     * läuft über die Route und nicht über ein direktes Datenbank-Update, damit
     * die Invalidierung im Produktivcode nachgewiesen ist.
     */
    public function test_eine_betragsaenderung_in_der_kostenpruefung_macht_vorschau_und_bestaetigung_ungueltig(): void
    {
        $szenario = $this->szenario();
        $builder = app(PreviewBuilder::class);

        $builder->rebuild($szenario['billingRun'], $szenario['user']);
        $this->bestaetige($szenario);

        self::assertTrue($builder->isValid($szenario['billingRun']->refresh()));
        self::assertNotNull($szenario['billingRun']->refresh()->review_confirmed_at);

        $this->actingAs($szenario['user'])->put(
            route('portal.pruefung.kosten.update', [
                'billingRun' => $szenario['billingRun']->getKey(),
                'costItem' => $szenario['costItem']->getKey(),
            ]),
            [
                'description' => 'Gebäudereinigung Treppenhaus',
                'betrag_euro' => '2.400,00',
                'cost_category_id' => $szenario['category']->getKey(),
            ]
        )->assertRedirect();

        self::assertSame(240000, $szenario['costItem']->refresh()->amount_cent);

        $lauf = $szenario['billingRun']->refresh();

        self::assertFalse($builder->isValid($lauf));
        self::assertNull($lauf->review_confirmed_at);
        self::assertNull($lauf->responsibility_confirmed_at);

        // Der Checkout ist damit gesperrt, bis Vorschau und Bestätigung erneuert sind.
        $this->actingAs($szenario['user'])->post(
            route('portal.checkout.store', ['billingRun' => $lauf->getKey()]),
            ['sofortige_ausfuehrung' => '1', 'vertragsgrundlagen' => '1']
        )->assertSessionHasErrors();

        self::assertNull($lauf->refresh()->getAttribute('paid_at'));

        self::assertSame(
            2,
            GeneratedDocument::query()
                ->where('billing_run_id', $szenario['billingRun']->getKey())
                ->where('status', GeneratedDocumentStatus::UNGUELTIG->value)
                ->where('kind', GeneratedDocumentKind::MIETERABRECHNUNG->value)
                ->count()
        );

        $builder->rebuild($lauf, $szenario['user']);

        self::assertTrue($builder->isValid($szenario['billingRun']->refresh()));
    }

    public function test_das_bestaetigen_einer_position_macht_die_vorschau_ungueltig(): void
    {
        $szenario = $this->szenario();
        $builder = app(PreviewBuilder::class);

        $builder->rebuild($szenario['billingRun'], $szenario['user']);
        $this->bestaetige($szenario);

        $weitere = CostItem::factory()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'billing_run_id' => $szenario['billingRun']->getKey(),
            'cost_category_id' => $szenario['category']->getKey(),
            'amount_cent' => 5000,
            'status' => CostItemStatus::VORGESCHLAGEN,
            'confirmed_at' => null,
        ]);

        $this->actingAs($szenario['user'])->post(
            route('portal.pruefung.kosten.bestaetigen', [
                'billingRun' => $szenario['billingRun']->getKey(),
                'costItem' => $weitere->getKey(),
            ])
        )->assertRedirect();

        $lauf = $szenario['billingRun']->refresh();

        self::assertFalse($builder->isValid($lauf));
        self::assertNull($lauf->review_confirmed_at);
    }

    public function test_die_manuelle_heizkostenerfassung_macht_die_vorschau_ungueltig(): void
    {
        $szenario = $this->szenario();
        $builder = app(PreviewBuilder::class);

        $builder->rebuild($szenario['billingRun'], $szenario['user']);
        $this->bestaetige($szenario);

        $this->actingAs($szenario['user'])->post(
            route('portal.pruefung.heizkosten.speichern', ['billingRun' => $szenario['billingRun']->getKey()]),
            [
                'einheiten' => [
                    (string) $szenario['units'][0]->getKey() => ['heizung' => '300,00'],
                    (string) $szenario['units'][1]->getKey() => ['heizung' => '150,00'],
                ],
            ]
        )->assertRedirect();

        $lauf = $szenario['billingRun']->refresh();

        self::assertFalse($builder->isValid($lauf));
        self::assertNull($lauf->review_confirmed_at);
    }

    public function test_der_wechsel_des_abrechnungswegs_macht_die_vorschau_ungueltig(): void
    {
        $szenario = $this->szenario();
        $builder = app(PreviewBuilder::class);

        $builder->rebuild($szenario['billingRun'], $szenario['user']);
        $this->bestaetige($szenario);

        $this->actingAs($szenario['user'])->put(
            route('portal.pruefung.weg.update', ['billingRun' => $szenario['billingRun']->getKey()]),
            ['mode' => BillingMode::QUICK_CONDO->value]
        )->assertRedirect();

        $lauf = $szenario['billingRun']->refresh();

        self::assertFalse($builder->isValid($lauf));
        self::assertNull($lauf->review_confirmed_at);
    }

    /**
     * Es gibt genau einen Bestätigungsweg. Der frühere Weg über die
     * Detailseite (abrechnungen.bestaetigen) prüfte die Vorschau nicht und
     * schrieb keinen Nachweis; er existiert nicht mehr.
     */
    public function test_es_gibt_keinen_zweiten_bestaetigungsweg_an_der_vorschau_vorbei(): void
    {
        $szenario = $this->szenario();
        $builder = app(PreviewBuilder::class);

        $builder->rebuild($szenario['billingRun'], $szenario['user']);
        $this->bestaetige($szenario);

        // Änderung nach der Bestätigung: Vorschau ungültig, Bestätigung zurückgenommen.
        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorauszahlungen.speichern', ['billingRun' => $szenario['billingRun']->getKey()]),
            ['zeilen' => [(string) $szenario['tenancies'][0]->getKey() => ['ist' => '100,00']]]
        )->assertRedirect();

        self::assertFalse(Route::has('portal.abrechnungen.bestaetigen'));

        $antwort = $this->actingAs($szenario['user'])->post(
            '/app/abrechnungen/'.$szenario['billingRun']->getKey().'/bestaetigen',
            ['werte_geprueft' => '1', 'verantwortung_uebernommen' => '1']
        );

        self::assertContains($antwort->getStatusCode(), [404, 405]);

        $lauf = $szenario['billingRun']->refresh();

        self::assertNull($lauf->review_confirmed_at);
        self::assertNull($lauf->responsibility_confirmed_at);
        self::assertFalse($builder->isValid($lauf));

        $antwort = $this->actingAs($szenario['user'])->get(
            route('portal.abrechnungen.show', ['billingRun' => $lauf->getKey()])
        );

        $antwort->assertOk();
        $antwort->assertDontSee('name="werte_geprueft"', false);
    }

    public function test_eine_unbestaetigte_kostenposition_verhindert_die_erzeugung_der_vorschau(): void
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

        $antwort = $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorschau.erzeugen', ['billingRun' => $szenario['billingRun']->getKey()])
        );

        $antwort->assertSessionHasErrors('vorschau');

        $fehler = session('errors')?->get('vorschau') ?? [];

        self::assertStringContainsString('Kostenpositionen offen', implode(' ', $fehler));
        self::assertFalse(app(PreviewBuilder::class)->isValid($szenario['billingRun']->refresh()));
        self::assertSame(0, CalculationSnapshot::query()->where('billing_run_id', $szenario['billingRun']->getKey())->count());

        // Die Seite nennt den Sperrgrund.
        $this->actingAs($szenario['user'])->get(
            route('portal.wizard.vorschau', ['billingRun' => $szenario['billingRun']->getKey()])
        )->assertSee('Kostenpositionen offen');
    }

    public function test_fehlende_verteilerschluessel_verhindern_die_erzeugung_der_vorschau(): void
    {
        $szenario = $this->szenario();

        $szenario['key']->delete();

        $antwort = $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorschau.erzeugen', ['billingRun' => $szenario['billingRun']->getKey()])
        );

        $antwort->assertSessionHasErrors('vorschau');

        $fehler = session('errors')?->get('vorschau') ?? [];

        self::assertStringContainsString('Schritt 8', implode(' ', $fehler));
        self::assertSame(0, CalculationSnapshot::query()->where('billing_run_id', $szenario['billingRun']->getKey())->count());
    }

    public function test_ein_gewerbliches_mietverhaeltnis_blockiert_die_vorschau_und_ist_nicht_wegklickbar(): void
    {
        $szenario = $this->szenario();

        $szenario['tenancies'][1]->forceFill(['kind' => TenancyKind::GEWERBE])->save();

        $antwort = $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorschau.erzeugen', ['billingRun' => $szenario['billingRun']->getKey()])
        );

        $antwort->assertSessionHasErrors('vorschau');
        self::assertFalse(app(PreviewBuilder::class)->isValid($szenario['billingRun']->refresh()));
        self::assertSame(0, CalculationSnapshot::query()->where('billing_run_id', $szenario['billingRun']->getKey())->count());

        /** @var ValidationIssue $aufgabe */
        $aufgabe = ValidationIssue::query()
            ->where('billing_run_id', $szenario['billingRun']->getKey())
            ->where('rule_code', 'GEWERBE_MIETVERHAELTNIS')
            ->firstOrFail();

        self::assertSame(ValidationSeverity::BLOCKER, $aufgabe->severity);
        self::assertSame(ValidationIssueStatus::OFFEN, $aufgabe->status);
        self::assertStringContainsString('Mietpartei B', (string) $aufgabe->description);

        // Der Blocker ist nicht durch eine Nutzerentscheidung auflösbar.
        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.pruefbericht.entscheiden', [
                'billingRun' => $szenario['billingRun']->getKey(),
                'issue' => $aufgabe->getKey(),
            ]),
            ['entscheidung' => 'Ich nehme das Gewerbe bewusst in Kauf.']
        )->assertSessionHasErrors('entscheidung');

        self::assertSame(ValidationIssueStatus::OFFEN, $aufgabe->refresh()->status);

        // Ohne Gewerbe läuft die Vorschau durch.
        $szenario['tenancies'][1]->forceFill(['kind' => TenancyKind::WOHNRAUM])->save();

        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorschau.erzeugen', ['billingRun' => $szenario['billingRun']->getKey()])
        )->assertSessionHasNoErrors();

        self::assertTrue(app(PreviewBuilder::class)->isValid($szenario['billingRun']->refresh()));
    }

    public function test_die_vorschau_mit_wasserzeichen_ist_ohne_bestaetigte_adresse_abrufbar(): void
    {
        $szenario = $this->szenario();
        $szenario['user']->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorschau.erzeugen', ['billingRun' => $szenario['billingRun']->getKey()])
        )->assertSessionHasNoErrors();

        /** @var GeneratedDocument $dokument */
        $dokument = GeneratedDocument::query()
            ->where('billing_run_id', $szenario['billingRun']->getKey())
            ->where('variant', GeneratedDocumentVariant::VORSCHAU->value)
            ->firstOrFail();

        $this->actingAs($szenario['user'])
            ->get(route('portal.downloads.stream', ['generatedDocument' => $dokument->getKey()]))
            ->assertOk();

        // Der finale Download bleibt der bestätigten Adresse vorbehalten.
        $final = GeneratedDocument::factory()->finalVariant()->create([
            'billing_run_id' => $szenario['billingRun']->getKey(),
            'organization_id' => $szenario['organization']->getKey(),
            'storage_disk' => $dokument->storage_disk,
            'storage_path' => $dokument->storage_path,
        ]);

        $this->actingAs($szenario['user'])
            ->get(route('portal.downloads.stream', ['generatedDocument' => $final->getKey()]))
            ->assertForbidden();
    }

    public function test_nach_der_bestaetigung_fuehrt_der_weg_zur_zahlung(): void
    {
        $szenario = $this->szenario();

        app(PreviewBuilder::class)->rebuild($szenario['billingRun'], $szenario['user']);

        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorschau.bestaetigen', ['billingRun' => $szenario['billingRun']->getKey()]),
            ['bestaetigung' => '1']
        )->assertRedirect(route('portal.checkout.show', ['billingRun' => $szenario['billingRun']->getKey()]));

        $antwort = $this->actingAs($szenario['user'])->get(
            route('portal.wizard.vorschau', ['billingRun' => $szenario['billingRun']->getKey()])
        );

        $antwort->assertOk();
        $antwort->assertSee(route('portal.checkout.show', ['billingRun' => $szenario['billingRun']->getKey()]), false);

        $detail = $this->actingAs($szenario['user'])->get(
            route('portal.abrechnungen.show', ['billingRun' => $szenario['billingRun']->getKey()])
        );

        $detail->assertOk();
        $detail->assertSee(route('portal.checkout.show', ['billingRun' => $szenario['billingRun']->getKey()]), false);
    }

    public function test_die_erzeugte_vorschau_wird_per_mail_angekuendigt(): void
    {
        Mail::fake();

        $szenario = $this->szenario();

        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorschau.erzeugen', ['billingRun' => $szenario['billingRun']->getKey()])
        )->assertSessionHasNoErrors();

        Mail::assertSent(VorschauBereitMail::class, function (VorschauBereitMail $mail) use ($szenario): bool {
            return $mail->hasTo((string) $szenario['user']->email);
        });

        self::assertSame(
            1,
            EmailMessage::query()
                ->where('billing_run_id', $szenario['billingRun']->getKey())
                ->where('template', 'vorschau-bereit')
                ->count()
        );
    }

    /**
     * @param  array{user: User, billingRun: BillingRun}  $szenario
     */
    private function bestaetige(array $szenario): void
    {
        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorschau.bestaetigen', ['billingRun' => $szenario['billingRun']->getKey()]),
            ['bestaetigung' => '1']
        )->assertRedirect();
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

    /**
     * Ein Blocker der Regel-Engine verhindert die Erzeugung auch dann, wenn
     * der Prüfbericht nie geöffnet wurde: die Engine läuft vor der Erzeugung.
     */
    public function test_ein_blocker_verhindert_die_erzeugung_der_vorschau(): void
    {
        $szenario = $this->szenario();

        // Überschneidung der Mietzeiträume in Wohnung A (Blocker der Regel-Engine).
        /** @var Tenancy $zweites */
        $zweites = Tenancy::factory()->create([
            'organization_id' => $szenario['organization']->getKey(),
            'property_id' => $szenario['property']->getKey(),
            'unit_id' => $szenario['units'][0]->getKey(),
            'tenant_display_name' => 'Mietpartei C',
            'starts_on' => '2025-06-01',
            'ends_on' => null,
        ]);
        $this->vorauszahlung($szenario['billingRun'], $zweites);

        $this->actingAs($szenario['user'])->post(
            route('portal.wizard.vorschau.erzeugen', ['billingRun' => $szenario['billingRun']->getKey()])
        )->assertSessionHasErrors('vorschau');

        self::assertFalse(app(PreviewBuilder::class)->isValid($szenario['billingRun']->refresh()));
        self::assertSame(0, CalculationSnapshot::query()->where('billing_run_id', $szenario['billingRun']->getKey())->count());

        self::assertTrue(
            ValidationIssue::query()
                ->where('billing_run_id', $szenario['billingRun']->getKey())
                ->where('rule_code', 'MIETZEIT_UEBERSCHNEIDUNG')
                ->where('severity', ValidationSeverity::BLOCKER->value)
                ->where('status', ValidationIssueStatus::OFFEN->value)
                ->exists()
        );
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

        self::assertStringContainsString('Schritt 7 ist noch nicht abgeschlossen', implode(' ', $fehler));
        self::assertStringContainsString('fehlen die tatsächlich geleisteten Vorauszahlungen', implode(' ', $fehler));
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
