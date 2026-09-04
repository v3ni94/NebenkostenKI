<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Application\Payment\Contracts\FinalDocumentViews;
use App\Application\Payment\Dto\FinalViewBundle;
use App\Application\Payment\HandleStripeEvent;
use App\Enums\BillingMode;
use App\Enums\BillingRunStatus;
use App\Enums\CalculationSnapshotStatus;
use App\Enums\GeneratedDocumentKind;
use App\Enums\GeneratedDocumentStatus;
use App\Enums\GeneratedDocumentVariant;
use App\Enums\PaymentStatus;
use App\Enums\UnitStatementStatus;
use App\Enums\WebhookProcessingStatus;
use App\Models\BillingRun;
use App\Models\CalculationSnapshot;
use App\Models\EmailMessage;
use App\Models\GeneratedDocument;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\Unit;
use App\Models\UnitStatement;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Payment\FakeFinalDocumentViews;
use Tests\Feature\Pdf\PdfFixtures;

/**
 * Nachlauf nach bestaetigter Zahlung im Adminbereich (Masterprompt 15, 20).
 *
 * Ein Kunde, der bezahlt hat, muss seine Leistung erhalten. Bezahlte Laeufe
 * ohne Finalisierung und ohne Rechnung sind sichtbar und ueber POST nachholbar.
 */
final class ZahlungsnachlaufTest extends AdminTestCase
{
    /**
     * Bezahlter Lauf im gewuenschten Status mit Snapshot und zwei Abrechnungen.
     *
     * @return array{lauf: BillingRun, zahlung: Payment}
     */
    private function bezahlterLauf(BillingRunStatus $status, ?string $failureCode = null): array
    {
        $kunde = $this->kunde();

        $kunde['organization']->forceFill([
            'billing_address_line' => 'Beispielweg 5',
            'billing_postal_code' => '40789',
            'billing_city' => 'Monheim am Rhein',
        ])->save();

        /** @var Property $objekt */
        $objekt = Property::factory()->create([
            'organization_id' => $kunde['organization']->getKey(),
            'created_by_user_id' => $kunde['user']->getKey(),
        ]);

        /** @var Unit $einheit */
        $einheit = Unit::factory()->create([
            'organization_id' => $kunde['organization']->getKey(),
            'property_id' => $objekt->getKey(),
        ]);

        /** @var BillingRun $lauf */
        $lauf = BillingRun::factory()->create([
            'organization_id' => $kunde['organization']->getKey(),
            'property_id' => $objekt->getKey(),
            'created_by_user_id' => $kunde['user']->getKey(),
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
            'billing_year' => 2025,
            'mode' => BillingMode::QUICK_CONDO,
            'status' => $status,
            'paid_at' => now()->subHour(),
            'finalized_at' => $status === BillingRunStatus::FINALIZED ? now()->subHour() : null,
            'failure_code' => $failureCode,
            'price_total_gross_cent' => 4980,
            'statement_count' => 2,
        ]);

        /** @var CalculationSnapshot $snapshot */
        $snapshot = CalculationSnapshot::factory()->create([
            'organization_id' => $kunde['organization']->getKey(),
            'billing_run_id' => $lauf->getKey(),
            'status' => CalculationSnapshotStatus::GESPERRT,
            'statement_count' => 2,
            'locked_at' => now()->subHour(),
        ]);

        $lauf->forceFill(['active_calculation_snapshot_id' => $snapshot->getKey()])->save();

        for ($nummer = 1; $nummer <= 2; $nummer++) {
            $mietverhaeltnis = Tenancy::factory()->create([
                'organization_id' => $kunde['organization']->getKey(),
                'property_id' => $objekt->getKey(),
                'unit_id' => $einheit->getKey(),
                'starts_on' => '2025-01-01',
                'ends_on' => null,
            ]);

            UnitStatement::factory()->create([
                'organization_id' => $kunde['organization']->getKey(),
                'billing_run_id' => $lauf->getKey(),
                'tenancy_id' => $mietverhaeltnis->getKey(),
                'unit_id' => $einheit->getKey(),
                'calculation_snapshot_id' => $snapshot->getKey(),
                'sequence_number' => $nummer,
                'status' => UnitStatementStatus::VORSCHAU,
            ]);
        }

        /** @var Payment $zahlung */
        $zahlung = Payment::factory()->create([
            'organization_id' => $kunde['organization']->getKey(),
            'billing_run_id' => $lauf->getKey(),
            'user_id' => $kunde['user']->getKey(),
            'amount_cent' => 4980,
            'statement_count' => 2,
            'status' => PaymentStatus::BEZAHLT,
            'paid_at' => now()->subHour(),
        ]);

        $this->app->instance(FinalDocumentViews::class, new FakeFinalDocumentViews(new FinalViewBundle(
            [PdfFixtures::statementView(), PdfFixtures::statementView()],
            [null, null],
            PdfFixtures::ownerOverviewView(),
        )));

        return ['lauf' => BillingRun::query()->findOrFail($lauf->getKey()), 'zahlung' => $zahlung];
    }

    public function test_die_uebersicht_zeigt_bezahlte_laeufe_ohne_finalisierung(): void
    {
        $vorgang = $this->bezahlterLauf(BillingRunStatus::FAILED, 'FINALISIERUNG_FEHLGESCHLAGEN');

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/zahlungsnachlauf');

        $antwort->assertOk();
        $antwort->assertSee('Bezahlt, aber nicht finalisiert');
        $antwort->assertSee((string) $vorgang['lauf']->getKey());
        $antwort->assertSee('FINALISIERUNG_FEHLGESCHLAGEN');
        $antwort->assertSee('Finalisierung nachholen');
    }

    public function test_die_finalisierung_wird_ueber_den_adminbereich_nachgeholt(): void
    {
        $this->bestaetigteBetreiberstammdaten();
        Mail::fake();

        $vorgang = $this->bezahlterLauf(BillingRunStatus::FAILED, 'FINALISIERUNG_FEHLGESCHLAGEN');
        $admin = $this->interneKennung();

        $this->actingAs($admin)
            ->post('/admin/zahlungsnachlauf/'.$vorgang['lauf']->getKey().'/finalisieren')
            ->assertRedirect('/admin/zahlungsnachlauf')
            ->assertSessionHas('status');

        /** @var BillingRun $lauf */
        $lauf = BillingRun::query()->findOrFail($vorgang['lauf']->getKey());

        self::assertSame(BillingRunStatus::FINALIZED, $lauf->getAttribute('status'));
        self::assertNotNull($lauf->getAttribute('finalized_at'));
        self::assertGreaterThanOrEqual(3, GeneratedDocument::query()
            ->where('billing_run_id', $lauf->getKey())
            ->where('variant', GeneratedDocumentVariant::FINAL->value)
            ->count());
        self::assertSame(1, Invoice::query()->where('billing_run_id', $lauf->getKey())->count());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.billing_run.finalization_requested',
            'subject_id' => $lauf->getKey(),
            'actor_user_id' => $admin->getKey(),
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'billing_run.finalization_retried',
            'subject_id' => $lauf->getKey(),
        ]);
    }

    public function test_ein_haengender_bezahlter_lauf_wird_ebenfalls_nachgeholt(): void
    {
        $this->bestaetigteBetreiberstammdaten();
        Mail::fake();

        // Die Finalisierung brach vor dem Statuswechsel ab, der Lauf blieb PAID.
        $vorgang = $this->bezahlterLauf(BillingRunStatus::PAID);

        $this->actingAs($this->interneKennung())
            ->get('/admin/zahlungsnachlauf')
            ->assertSee((string) $vorgang['lauf']->getKey());

        $this->actingAs($this->interneKennung())
            ->post('/admin/zahlungsnachlauf/'.$vorgang['lauf']->getKey().'/finalisieren')
            ->assertSessionHas('status');

        self::assertSame(BillingRunStatus::FINALIZED, BillingRun::query()
            ->findOrFail($vorgang['lauf']->getKey())
            ->getAttribute('status'));
    }

    public function test_ein_finalisierter_lauf_wird_nicht_erneut_finalisiert(): void
    {
        $vorgang = $this->bezahlterLauf(BillingRunStatus::FINALIZED);

        $this->actingAs($this->interneKennung())
            ->post('/admin/zahlungsnachlauf/'.$vorgang['lauf']->getKey().'/finalisieren')
            ->assertRedirect('/admin/zahlungsnachlauf')
            ->assertSessionHas('hinweis');

        self::assertSame(0, GeneratedDocument::query()->count());
    }

    public function test_die_rechnung_wird_fuer_einen_bezahlten_lauf_nachgeholt(): void
    {
        $this->bestaetigteBetreiberstammdaten();
        Mail::fake();

        $vorgang = $this->bezahlterLauf(BillingRunStatus::FINALIZED);
        $admin = $this->interneKennung();

        $this->actingAs($admin)
            ->get('/admin/zahlungsnachlauf')
            ->assertSee('Bezahlt und finalisiert, aber ohne Rechnung')
            ->assertSee('Rechnung nachholen');

        $this->actingAs($admin)
            ->post('/admin/zahlungsnachlauf/'.$vorgang['lauf']->getKey().'/rechnung')
            ->assertRedirect('/admin/zahlungsnachlauf')
            ->assertSessionHas('status');

        /** @var Invoice $rechnung */
        $rechnung = Invoice::query()->where('billing_run_id', $vorgang['lauf']->getKey())->firstOrFail();

        // Die Rechnung stellt den bezahlten Betrag.
        self::assertSame(4980, (int) $rechnung->getAttribute('gross_cent'));
        self::assertSame(
            (int) $rechnung->getAttribute('gross_cent'),
            (int) $rechnung->getAttribute('net_cent') + (int) $rechnung->getAttribute('tax_cent'),
        );
        self::assertSame('Beispielweg 5', (string) $rechnung->getAttribute('customer_address_line'));

        // Der Beleg liegt als Final-Dokument am Lauf und ist damit im
        // Abschlussbereich des Kunden abrufbar.
        self::assertSame(1, GeneratedDocument::query()
            ->where('billing_run_id', $vorgang['lauf']->getKey())
            ->where('invoice_id', $rechnung->getKey())
            ->where('kind', GeneratedDocumentKind::HVM_RECHNUNG->value)
            ->where('variant', GeneratedDocumentVariant::FINAL->value)
            ->where('status', GeneratedDocumentStatus::AKTIV->value)
            ->count());

        // Die Rechnungsmail wurde nachgesendet.
        self::assertSame(1, EmailMessage::query()
            ->where('billing_run_id', $vorgang['lauf']->getKey())
            ->where('template', 'hvm-rechnung-verfuegbar')
            ->count());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'invoice.issued_late',
            'subject_id' => $rechnung->getKey(),
        ]);

        // Der Fall ist erledigt und erscheint nicht mehr.
        $this->actingAs($admin)
            ->get('/admin/zahlungsnachlauf')
            ->assertDontSee((string) $vorgang['lauf']->getKey());
    }

    public function test_ohne_bestaetigte_betreiberstammdaten_wird_keine_rechnung_nachgeholt(): void
    {
        $vorgang = $this->bezahlterLauf(BillingRunStatus::FINALIZED);

        $this->actingAs($this->interneKennung())
            ->post('/admin/zahlungsnachlauf/'.$vorgang['lauf']->getKey().'/rechnung')
            ->assertRedirect('/admin/zahlungsnachlauf')
            ->assertSessionHas('hinweis');

        self::assertSame(0, Invoice::query()->count());
    }

    public function test_eine_zweite_nachholung_erzeugt_keine_zweite_rechnung(): void
    {
        $this->bestaetigteBetreiberstammdaten();
        Mail::fake();

        $vorgang = $this->bezahlterLauf(BillingRunStatus::FINALIZED);

        for ($versuch = 0; $versuch < 2; $versuch++) {
            $this->actingAs($this->interneKennung())
                ->post('/admin/zahlungsnachlauf/'.$vorgang['lauf']->getKey().'/rechnung');
        }

        self::assertSame(1, Invoice::query()->where('billing_run_id', $vorgang['lauf']->getKey())->count());
    }

    public function test_die_uebersicht_zeigt_zahlungseingaenge_ohne_lauf(): void
    {
        $vorgang = $this->bezahlterLauf(BillingRunStatus::CANCELLED);
        $vorgang['zahlung']->forceFill(['failure_code' => 'ZAHLUNG_OHNE_LAUF'])->save();
        $vorgang['lauf']->forceFill(['paid_at' => null])->save();

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/zahlungsnachlauf');

        $antwort->assertOk();
        $antwort->assertSee('Zahlungseingang ohne freischaltbaren Lauf');
        $antwort->assertSee((string) $vorgang['zahlung']->getKey());
        $antwort->assertSee('ZAHLUNG_OHNE_LAUF');
    }

    public function test_die_uebersicht_zeigt_liegen_gebliebene_benachrichtigungen_des_anbieters(): void
    {
        WebhookEvent::factory()->create([
            'provider_event_id' => 'evt_test_liegen_geblieben_admin',
            'processing_status' => WebhookProcessingStatus::EMPFANGEN,
            'processed_at' => null,
            'received_at' => now()->subMinutes(HandleStripeEvent::STALE_RECEIVED_MINUTES + 1),
            'attempts' => 1,
        ]);

        // Ein frisch empfangenes Ereignis ist noch in Verarbeitung und kein Fall.
        WebhookEvent::factory()->create([
            'provider_event_id' => 'evt_test_in_verarbeitung',
            'processing_status' => WebhookProcessingStatus::EMPFANGEN,
            'processed_at' => null,
            'received_at' => now(),
        ]);

        $antwort = $this->actingAs($this->interneKennung())->get('/admin/zahlungsnachlauf');

        $antwort->assertOk();
        $antwort->assertSee('Liegen gebliebene Benachrichtigungen des Zahlungsanbieters');
        $antwort->assertSee('evt_test_liegen_geblieben_admin');
        $antwort->assertDontSee('evt_test_in_verarbeitung');
    }

    public function test_uebersicht_und_zahlungsseite_zeigen_die_anzahl_der_offenen_faelle(): void
    {
        $this->bezahlterLauf(BillingRunStatus::FAILED, 'FINALISIERUNG_FEHLGESCHLAGEN');

        $ohneLauf = $this->bezahlterLauf(BillingRunStatus::CANCELLED);
        $ohneLauf['zahlung']->forceFill(['failure_code' => 'ZAHLUNG_OHNE_LAUF'])->save();
        $ohneLauf['lauf']->forceFill(['paid_at' => null])->save();

        $uebersicht = $this->actingAs($this->interneKennung())->get('/admin');

        $uebersicht->assertOk();
        $uebersicht->assertSee('Zahlungsnachlauf');
        $uebersicht->assertSee('Offene Fälle nach bestätigter Zahlung: <strong>2</strong>', false);
        $uebersicht->assertSee('Zahlungen ohne freischaltbaren Abrechnungslauf: 1');

        $zahlungen = $this->actingAs($this->interneKennung())->get('/admin/zahlungen');

        $zahlungen->assertOk();
        $zahlungen->assertSee('offene Fälle nach bestätigter Zahlung (2)');
        $zahlungen->assertSee('Zahlungseingang ohne freischaltbaren Lauf');
    }

    public function test_ohne_rechnungsanschrift_des_kunden_wird_keine_rechnung_nachgeholt(): void
    {
        $this->bestaetigteBetreiberstammdaten();
        Mail::fake();

        $vorgang = $this->bezahlterLauf(BillingRunStatus::FINALIZED);

        Organization::query()
            ->whereKey($vorgang['lauf']->getAttribute('organization_id'))
            ->update(['billing_address_line' => null, 'billing_postal_code' => null, 'billing_city' => null]);

        $this->actingAs($this->interneKennung())
            ->post('/admin/zahlungsnachlauf/'.$vorgang['lauf']->getKey().'/rechnung')
            ->assertRedirect('/admin/zahlungsnachlauf')
            ->assertSessionHas('hinweis', static fn (string $hinweis): bool => str_contains($hinweis, 'Rechnungsanschrift'));

        self::assertSame(0, Invoice::query()->count());

        // Der Fall bleibt sichtbar.
        $this->actingAs($this->interneKennung())
            ->get('/admin/zahlungsnachlauf')
            ->assertSee((string) $vorgang['lauf']->getKey());
    }

    public function test_der_konsolenbefehl_holt_rechnung_und_finalisierung_nach(): void
    {
        $this->bestaetigteBetreiberstammdaten();
        Mail::fake();

        $vorgang = $this->bezahlterLauf(BillingRunStatus::FINALIZED);

        $this->artisan('smartabrechnen:retry-finalization')->assertSuccessful();

        self::assertSame(1, Invoice::query()->where('billing_run_id', $vorgang['lauf']->getKey())->count());
    }

    public function test_ein_kunde_erreicht_den_zahlungsnachlauf_nicht(): void
    {
        $vorgang = $this->bezahlterLauf(BillingRunStatus::FAILED, 'FINALISIERUNG_FEHLGESCHLAGEN');
        $kunde = $this->kunde();

        $this->actingAs($kunde['user'])
            ->post('/admin/zahlungsnachlauf/'.$vorgang['lauf']->getKey().'/finalisieren')
            ->assertNotFound();

        self::assertSame(BillingRunStatus::FAILED, BillingRun::query()
            ->findOrFail($vorgang['lauf']->getKey())
            ->getAttribute('status'));
    }
}
