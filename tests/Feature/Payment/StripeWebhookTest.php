<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Application\Payment\Dto\FinalViewBundle;
use App\Application\Payment\HandleStripeEvent;
use App\Application\Payment\PaymentRecoveryOverview;
use App\Enums\BillingRunStatus;
use App\Enums\GeneratedDocumentVariant;
use App\Enums\PaymentStatus;
use App\Enums\UnitStatementStatus;
use App\Enums\WebhookProcessingStatus;
use App\Enums\WebhookSignatureStatus;
use App\Models\AuditLog;
use App\Models\BillingRun;
use App\Models\CalculationSnapshot;
use App\Models\GeneratedDocument;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\UnitStatement;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Feature\Pdf\PdfFixtures;

/**
 * Providerbenachrichtigungen: Signatur, Idempotenz, Betragsvergleich und
 * Abbruchwege (Abschnitt 15.1, 23.3).
 *
 * Es entsteht in keinem Test ein echter Aufruf. Die Signaturen werden mit dem
 * Platzhaltergeheimnis aus phpunit.xml selbst erzeugt.
 */
final class StripeWebhookTest extends PaymentTestCase
{
    /**
     * @return array{lauf: BillingRun, zahlung: Payment}
     */
    private function offenerCheckout(int $anzahl = 2): array
    {
        $this->bestaetigteBetreiberstammdaten();

        $daten = $this->vorschaubereiterLauf($anzahl);

        $this->actingAs($daten['user'])
            ->post(route('portal.checkout.store', ['billingRun' => $daten['billingRun']->getKey()]), [
                'sofortige_ausfuehrung' => '1',
                'vertragsgrundlagen' => '1',
            ])
            ->assertRedirect();

        // Die Aufbereitung des Snapshots liefert im Test feste Beispieldaten.
        $this->bindeFinalDocumentViews(new FinalViewBundle(
            [PdfFixtures::statementView()],
            [null],
            PdfFixtures::ownerOverviewView(),
        ));

        /** @var Payment $zahlung */
        $zahlung = Payment::query()->where('billing_run_id', $daten['billingRun']->getKey())->firstOrFail();

        return [
            'lauf' => BillingRun::query()->findOrFail($daten['billingRun']->getKey()),
            'zahlung' => $zahlung,
        ];
    }

    public function test_eine_falsche_signatur_wird_abgelehnt_und_schaltet_nichts_frei(): void
    {
        $vorgang = $this->offenerCheckout();
        $payload = $this->erfolgsnutzlast($vorgang['zahlung']);

        $antwort = $this->sendeWebhook($payload, $this->signatur($payload, null, 'whsec_falsches_geheimnis'));

        $antwort->assertStatus(400);

        /** @var Payment $zahlung */
        $zahlung = Payment::query()->findOrFail($vorgang['zahlung']->getKey());
        /** @var BillingRun $lauf */
        $lauf = BillingRun::query()->findOrFail($vorgang['lauf']->getKey());

        self::assertSame(PaymentStatus::AUSSTEHEND, $zahlung->getAttribute('status'));
        self::assertSame(BillingRunStatus::CHECKOUT_PENDING, $lauf->getAttribute('status'));
        self::assertNull($lauf->getAttribute('paid_at'));

        /** @var WebhookEvent $eintrag */
        $eintrag = WebhookEvent::query()->firstOrFail();

        self::assertSame(WebhookSignatureStatus::UNGUELTIG, $eintrag->getAttribute('signature_status'));
        self::assertNull($eintrag->getAttribute('payload'));
    }

    public function test_eine_fehlende_signatur_wird_abgelehnt(): void
    {
        $vorgang = $this->offenerCheckout();
        $payload = $this->erfolgsnutzlast($vorgang['zahlung']);

        $antwort = $this->call(
            'POST',
            route('webhooks.stripe'),
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $payload,
        );

        $antwort->assertStatus(400);

        /** @var WebhookEvent $eintrag */
        $eintrag = WebhookEvent::query()->firstOrFail();

        self::assertSame(WebhookSignatureStatus::FEHLT, $eintrag->getAttribute('signature_status'));
    }

    public function test_eine_veraltete_signatur_wird_abgelehnt(): void
    {
        $vorgang = $this->offenerCheckout();
        $payload = $this->erfolgsnutzlast($vorgang['zahlung']);

        $antwort = $this->sendeWebhook($payload, $this->signatur($payload, time() - 3600));

        $antwort->assertStatus(400);
        self::assertSame(PaymentStatus::AUSSTEHEND, Payment::query()
            ->findOrFail($vorgang['zahlung']->getKey())
            ->getAttribute('status'));
    }

    public function test_eine_gueltige_erfolgsmeldung_schaltet_die_zahlung_frei(): void
    {
        $vorgang = $this->offenerCheckout();

        $antwort = $this->sendeWebhook($this->erfolgsnutzlast($vorgang['zahlung']));

        $antwort->assertOk();
        $antwort->assertJson(['status' => 'verarbeitet']);

        /** @var Payment $zahlung */
        $zahlung = Payment::query()->findOrFail($vorgang['zahlung']->getKey());
        /** @var BillingRun $lauf */
        $lauf = BillingRun::query()->findOrFail($vorgang['lauf']->getKey());

        self::assertSame(PaymentStatus::BEZAHLT, $zahlung->getAttribute('status'));
        self::assertNotNull($zahlung->getAttribute('paid_at'));
        self::assertNotNull($lauf->getAttribute('paid_at'));
        self::assertSame(BillingRunStatus::FINALIZED, $lauf->getAttribute('status'));
    }

    public function test_ein_abweichender_betrag_schaltet_nicht_frei(): void
    {
        $vorgang = $this->offenerCheckout();

        $antwort = $this->sendeWebhook($this->erfolgsnutzlast($vorgang['zahlung'], ['amount_total' => 1]));

        $antwort->assertOk();
        $antwort->assertJson(['status' => 'ignoriert']);

        /** @var Payment $zahlung */
        $zahlung = Payment::query()->findOrFail($vorgang['zahlung']->getKey());
        /** @var BillingRun $lauf */
        $lauf = BillingRun::query()->findOrFail($vorgang['lauf']->getKey());

        self::assertSame(PaymentStatus::AUSSTEHEND, $zahlung->getAttribute('status'));
        self::assertSame(BillingRunStatus::CHECKOUT_PENDING, $lauf->getAttribute('status'));
        self::assertNull($lauf->getAttribute('paid_at'));

        /** @var WebhookEvent $eintrag */
        $eintrag = WebhookEvent::query()->where('event_type', 'checkout.session.completed')->firstOrFail();

        self::assertSame('BETRAG_ABWEICHEND', $eintrag->getAttribute('error_code'));
    }

    public function test_eine_abweichende_waehrung_schaltet_nicht_frei(): void
    {
        $vorgang = $this->offenerCheckout();

        $this->sendeWebhook($this->erfolgsnutzlast($vorgang['zahlung'], ['currency' => 'usd']))
            ->assertJson(['status' => 'ignoriert']);

        self::assertSame(PaymentStatus::AUSSTEHEND, Payment::query()
            ->findOrFail($vorgang['zahlung']->getKey())
            ->getAttribute('status'));

        /** @var WebhookEvent $eintrag */
        $eintrag = WebhookEvent::query()->where('event_type', 'checkout.session.completed')->firstOrFail();

        self::assertSame('WAEHRUNG_ABWEICHEND', $eintrag->getAttribute('error_code'));
    }

    public function test_ein_abweichender_abrechnungslauf_schaltet_nicht_frei(): void
    {
        $vorgang = $this->offenerCheckout();

        $this->sendeWebhook($this->erfolgsnutzlast($vorgang['zahlung'], [
            'metadata' => ['billing_run_id' => '01FREMDERLAUF0000000000000', 'payment_id' => (string) $vorgang['zahlung']->getKey()],
        ]))->assertJson(['status' => 'ignoriert']);

        self::assertSame(PaymentStatus::AUSSTEHEND, Payment::query()
            ->findOrFail($vorgang['zahlung']->getKey())
            ->getAttribute('status'));

        /** @var WebhookEvent $eintrag */
        $eintrag = WebhookEvent::query()->where('event_type', 'checkout.session.completed')->firstOrFail();

        self::assertSame('ABRECHNUNGSLAUF_ABWEICHEND', $eintrag->getAttribute('error_code'));
    }

    public function test_eine_offene_zahlung_schaltet_nicht_frei(): void
    {
        $vorgang = $this->offenerCheckout();

        $this->sendeWebhook($this->erfolgsnutzlast($vorgang['zahlung'], ['payment_status' => 'unpaid']))
            ->assertJson(['status' => 'ignoriert']);

        self::assertSame(BillingRunStatus::CHECKOUT_PENDING, BillingRun::query()
            ->findOrFail($vorgang['lauf']->getKey())
            ->getAttribute('status'));
    }

    public function test_die_erstmeldung_einer_asynchronen_zahlart_ist_keine_abweichung(): void
    {
        $vorgang = $this->offenerCheckout();

        // Lastschrift: checkout.session.completed kommt regulaer mit
        // payment_status unpaid, die Bestaetigung folgt spaeter.
        $this->sendeWebhook($this->erfolgsnutzlast($vorgang['zahlung'], ['payment_status' => 'unpaid']))
            ->assertJson(['status' => 'ignoriert']);

        /** @var WebhookEvent $eintrag */
        $eintrag = WebhookEvent::query()->where('event_type', 'checkout.session.completed')->firstOrFail();

        self::assertSame('ZAHLUNGSSTATUS_OFFEN', $eintrag->getAttribute('error_code'));
        self::assertStringNotContainsString('stimmt nicht', (string) $eintrag->getAttribute('error_message'));
        self::assertSame(0, AuditLog::query()->where('action', 'payment.mismatch_rejected')->count());

        // Die spaetere Bestaetigung schaltet frei.
        $bestaetigung = $this->nutzlast('checkout.session.async_payment_succeeded', [
            'id' => (string) $vorgang['zahlung']->getAttribute('checkout_session_id'),
            'object' => 'checkout.session',
            'payment_intent' => 'pi_test_sepa',
            'payment_status' => 'paid',
            'amount_total' => (int) $vorgang['zahlung']->getAttribute('amount_cent'),
            'currency' => 'eur',
            'metadata' => ['payment_id' => (string) $vorgang['zahlung']->getKey()],
        ]);

        $this->sendeWebhook($bestaetigung)->assertJson(['status' => 'verarbeitet']);

        self::assertSame(BillingRunStatus::FINALIZED, BillingRun::query()
            ->findOrFail($vorgang['lauf']->getKey())
            ->getAttribute('status'));
    }

    public function test_eine_zahlung_nach_abbruch_des_checkouts_schaltet_den_lauf_trotzdem_frei(): void
    {
        $vorgang = $this->offenerCheckout();

        /** @var User $nutzer */
        $nutzer = User::query()->findOrFail($vorgang['zahlung']->getAttribute('user_id'));

        // Der Nutzer bricht den Vorgang ab, die Sitzung beim Anbieter war aber
        // bereits mit ausstehender Lastschrift abgeschlossen.
        $this->actingAs($nutzer)
            ->delete(route('portal.checkout.destroy', ['billingRun' => $vorgang['lauf']->getKey()]))
            ->assertRedirect();

        self::assertSame(BillingRunStatus::PREVIEW_READY, BillingRun::query()
            ->findOrFail($vorgang['lauf']->getKey())
            ->getAttribute('status'));

        // Zwei Tage spaeter: das Geld ist eingezogen.
        $this->sendeWebhook($this->nutzlast('checkout.session.async_payment_succeeded', [
            'id' => (string) $vorgang['zahlung']->getAttribute('checkout_session_id'),
            'object' => 'checkout.session',
            'payment_intent' => 'pi_test_nach_abbruch',
            'payment_status' => 'paid',
            'amount_total' => (int) $vorgang['zahlung']->getAttribute('amount_cent'),
            'currency' => 'eur',
            'metadata' => ['payment_id' => (string) $vorgang['zahlung']->getKey()],
        ]))->assertOk()->assertJson(['status' => 'verarbeitet']);

        /** @var Payment $zahlung */
        $zahlung = Payment::query()->findOrFail($vorgang['zahlung']->getKey());
        /** @var BillingRun $lauf */
        $lauf = BillingRun::query()->findOrFail($vorgang['lauf']->getKey());

        // Der Kunde hat bezahlt und erhaelt seine Leistung.
        self::assertSame(PaymentStatus::BEZAHLT, $zahlung->getAttribute('status'));
        self::assertNull($zahlung->getAttribute('failure_code'));
        self::assertNotNull($lauf->getAttribute('paid_at'));
        self::assertSame(BillingRunStatus::FINALIZED, $lauf->getAttribute('status'));
        self::assertSame(1, Invoice::query()->count());
    }

    public function test_ein_abgebrochener_lauf_beendet_den_zahlungsvorgang_und_ein_spaeterer_eingang_wird_festgehalten(): void
    {
        $vorgang = $this->offenerCheckout();

        /** @var User $nutzer */
        $nutzer = User::query()->findOrFail($vorgang['zahlung']->getAttribute('user_id'));

        // Abbruch des gesamten Laufs waehrend CHECKOUT_PENDING.
        $this->actingAs($nutzer)
            ->post(route('portal.abrechnungen.abbrechen', ['billingRun' => $vorgang['lauf']->getKey()]))
            ->assertRedirect(route('portal.abrechnungen.index'));

        /** @var Payment $zahlung */
        $zahlung = Payment::query()->findOrFail($vorgang['zahlung']->getKey());

        self::assertSame(BillingRunStatus::CANCELLED, BillingRun::query()
            ->findOrFail($vorgang['lauf']->getKey())
            ->getAttribute('status'));
        self::assertSame(PaymentStatus::ABGEBROCHEN, $zahlung->getAttribute('status'));
        self::assertContains(
            (string) $zahlung->getAttribute('checkout_session_id'),
            $this->checkoutClient->expiredSessions,
        );

        // Der Kunde zahlt dennoch im noch offenen Anbieterfenster.
        $this->sendeWebhook($this->erfolgsnutzlast($vorgang['zahlung']))
            ->assertOk()
            ->assertJson(['status' => 'verarbeitet']);

        $zahlung->refresh();
        /** @var BillingRun $lauf */
        $lauf = BillingRun::query()->findOrFail($vorgang['lauf']->getKey());

        // Der Eingang ist festgehalten und fuer den Betrieb sichtbar, der
        // abgebrochene Lauf bleibt unangetastet.
        self::assertSame(PaymentStatus::BEZAHLT, $zahlung->getAttribute('status'));
        self::assertSame('ZAHLUNG_OHNE_LAUF', (string) $zahlung->getAttribute('failure_code'));
        self::assertNotNull($zahlung->getAttribute('paid_at'));
        self::assertSame(BillingRunStatus::CANCELLED, $lauf->getAttribute('status'));
        self::assertNull($lauf->getAttribute('paid_at'));
        self::assertSame(0, Invoice::query()->count());
        self::assertSame(1, AuditLog::query()->where('action', 'payment.received_without_run')->count());

        /** @var WebhookEvent $eintrag */
        $eintrag = WebhookEvent::query()->where('event_type', 'checkout.session.completed')->firstOrFail();

        self::assertSame(WebhookProcessingStatus::VERARBEITET, $eintrag->getAttribute('processing_status'));
        self::assertSame('ZAHLUNG_OHNE_LAUF', $eintrag->getAttribute('error_code'));
        self::assertCount(1, app(PaymentRecoveryOverview::class)->paymentsWithoutRun());
    }

    public function test_das_loeschen_eines_laufs_beendet_den_zahlungsvorgang(): void
    {
        $vorgang = $this->offenerCheckout();

        /** @var User $nutzer */
        $nutzer = User::query()->findOrFail($vorgang['zahlung']->getAttribute('user_id'));

        $this->actingAs($nutzer)
            ->delete(route('portal.abrechnungen.destroy', ['billingRun' => $vorgang['lauf']->getKey()]))
            ->assertRedirect(route('portal.abrechnungen.index'));

        /** @var Payment $zahlung */
        $zahlung = Payment::query()->findOrFail($vorgang['zahlung']->getKey());

        self::assertSame(PaymentStatus::ABGEBROCHEN, $zahlung->getAttribute('status'));
        self::assertContains(
            (string) $zahlung->getAttribute('checkout_session_id'),
            $this->checkoutClient->expiredSessions,
        );

        // Ein dennoch eingehender Zahlungseingang geht nicht verloren.
        $this->sendeWebhook($this->erfolgsnutzlast($vorgang['zahlung']))->assertJson(['status' => 'verarbeitet']);

        $zahlung->refresh();

        self::assertSame(PaymentStatus::BEZAHLT, $zahlung->getAttribute('status'));
        self::assertSame('ABRECHNUNGSLAUF_NICHT_GEFUNDEN', (string) $zahlung->getAttribute('failure_code'));
        self::assertCount(1, app(PaymentRecoveryOverview::class)->paymentsWithoutRun());
    }

    public function test_ein_veraenderter_berechnungsstand_wird_nicht_mit_der_alten_zahlung_freigeschaltet(): void
    {
        $vorgang = $this->offenerCheckout(2);

        /** @var User $nutzer */
        $nutzer = User::query()->findOrFail($vorgang['zahlung']->getAttribute('user_id'));

        $this->actingAs($nutzer)
            ->delete(route('portal.checkout.destroy', ['billingRun' => $vorgang['lauf']->getKey()]))
            ->assertRedirect();

        // Nach dem Abbruch kommt eine dritte Abrechnung zum aktiven Stand hinzu.
        UnitStatement::factory()->create([
            'organization_id' => $vorgang['lauf']->getAttribute('organization_id'),
            'billing_run_id' => $vorgang['lauf']->getKey(),
            'calculation_snapshot_id' => $vorgang['lauf']->getAttribute('active_calculation_snapshot_id'),
            'status' => UnitStatementStatus::VORSCHAU,
        ]);

        $this->sendeWebhook($this->erfolgsnutzlast($vorgang['zahlung']))->assertJson(['status' => 'verarbeitet']);

        /** @var Payment $zahlung */
        $zahlung = Payment::query()->findOrFail($vorgang['zahlung']->getKey());
        /** @var BillingRun $lauf */
        $lauf = BillingRun::query()->findOrFail($vorgang['lauf']->getKey());

        self::assertSame(PaymentStatus::BEZAHLT, $zahlung->getAttribute('status'));
        self::assertSame('BERECHNUNGSSTAND_GEAENDERT', (string) $zahlung->getAttribute('failure_code'));
        self::assertSame(BillingRunStatus::PREVIEW_READY, $lauf->getAttribute('status'));
        self::assertNull($lauf->getAttribute('paid_at'));

        // Ein zweiter Checkout zu diesem Lauf wird abgelehnt: der Kunde soll
        // nicht doppelt zahlen, der Fall wird vom Betrieb geklaert.
        $this->actingAs($nutzer)
            ->from(route('portal.checkout.show', ['billingRun' => $lauf->getKey()]))
            ->post(route('portal.checkout.store', ['billingRun' => $lauf->getKey()]), [
                'sofortige_ausfuehrung' => '1',
                'vertragsgrundlagen' => '1',
            ])
            ->assertSessionHasErrors(['zahlung']);

        self::assertSame(1, Payment::query()->where('billing_run_id', $lauf->getKey())->count());
    }

    public function test_eine_nach_verarbeitungsfehler_wiederzugestellte_meldung_wird_erneut_verarbeitet(): void
    {
        $vorgang = $this->offenerCheckout();
        $payload = $this->erfolgsnutzlast($vorgang['zahlung'], [], 'evt_test_wiederzustellung_1');

        // Die Datenbank ist beim ersten Speichern der Zahlung kurz nicht
        // erreichbar. Der Fehler tritt genau einmal auf.
        $ersterVersuch = true;

        Payment::saving(static function () use (&$ersterVersuch): void {
            if ($ersterVersuch) {
                $ersterVersuch = false;

                throw new RuntimeException('Datenbankverbindung unterbrochen.');
            }
        });

        $this->sendeWebhook($payload)->assertStatus(500);

        /** @var WebhookEvent $eintrag */
        $eintrag = WebhookEvent::query()->where('provider_event_id', 'evt_test_wiederzustellung_1')->firstOrFail();

        self::assertSame(WebhookProcessingStatus::FEHLGESCHLAGEN, $eintrag->getAttribute('processing_status'));
        self::assertSame(PaymentStatus::AUSSTEHEND, Payment::query()
            ->findOrFail($vorgang['zahlung']->getKey())
            ->getAttribute('status'));

        // Der Anbieter stellt dieselbe Meldung erneut zu.
        $this->sendeWebhook($payload)->assertOk()->assertJson(['status' => 'verarbeitet']);

        $eintrag->refresh();

        self::assertSame(1, WebhookEvent::query()->where('provider_event_id', 'evt_test_wiederzustellung_1')->count());
        self::assertSame(2, (int) $eintrag->getAttribute('attempts'));
        self::assertSame(WebhookProcessingStatus::VERARBEITET, $eintrag->getAttribute('processing_status'));
        self::assertNull($eintrag->getAttribute('error_code'));
        self::assertSame(BillingRunStatus::FINALIZED, BillingRun::query()
            ->findOrFail($vorgang['lauf']->getKey())
            ->getAttribute('status'));
    }

    public function test_eine_wiederzustellung_auf_ein_liegen_gebliebenes_ereignis_wird_nicht_mit_200_quittiert(): void
    {
        $vorgang = $this->offenerCheckout();
        $payload = $this->erfolgsnutzlast($vorgang['zahlung'], [], 'evt_test_liegen_geblieben_1');

        // Die erste Zustellung wurde gespeichert, der Prozess brach danach
        // hart ab: kein Ergebnis, kein processed_at.
        WebhookEvent::factory()->create([
            'provider_event_id' => 'evt_test_liegen_geblieben_1',
            'processing_status' => WebhookProcessingStatus::EMPFANGEN,
            'processed_at' => null,
            'received_at' => now(),
            'attempts' => 1,
        ]);

        // Der Anbieter stellt innerhalb der Wartezeit erneut zu. Ein 200 wuerde
        // die Zustellkette beenden, obwohl nichts verarbeitet wurde.
        $this->sendeWebhook($payload)->assertStatus(500);

        /** @var WebhookEvent $eintrag */
        $eintrag = WebhookEvent::query()->where('provider_event_id', 'evt_test_liegen_geblieben_1')->firstOrFail();

        self::assertSame(WebhookProcessingStatus::EMPFANGEN, $eintrag->getAttribute('processing_status'));
        self::assertSame(2, (int) $eintrag->getAttribute('attempts'));
        self::assertNull($eintrag->getAttribute('processed_at'));
        self::assertSame(PaymentStatus::AUSSTEHEND, Payment::query()
            ->findOrFail($vorgang['zahlung']->getKey())
            ->getAttribute('status'));

        // Der liegen gebliebene Fall ist im Zahlungsnachlauf sichtbar, sobald
        // die Wartezeit abgelaufen ist.
        self::assertCount(0, app(PaymentRecoveryOverview::class)->staleReceivedEvents());

        $this->travel(HandleStripeEvent::STALE_RECEIVED_MINUTES + 1)->minutes();

        self::assertCount(1, app(PaymentRecoveryOverview::class)->staleReceivedEvents());

        // Nach der Wartezeit wird die naechste Zustellung verarbeitet.
        $this->sendeWebhook($payload)->assertOk()->assertJson(['status' => 'verarbeitet']);

        $eintrag->refresh();

        self::assertSame(WebhookProcessingStatus::VERARBEITET, $eintrag->getAttribute('processing_status'));
        self::assertNotNull($eintrag->getAttribute('processed_at'));
        self::assertSame(3, (int) $eintrag->getAttribute('attempts'));
        self::assertSame(BillingRunStatus::FINALIZED, BillingRun::query()
            ->findOrFail($vorgang['lauf']->getKey())
            ->getAttribute('status'));
        self::assertCount(0, app(PaymentRecoveryOverview::class)->staleReceivedEvents());
    }

    public function test_ein_datenbankfehler_beim_speichern_der_meldung_wird_nicht_als_duplikat_gewertet(): void
    {
        $vorgang = $this->offenerCheckout();

        // Die Tabelle ist nicht erreichbar. Das ist kein Duplikat, sondern ein
        // Fehler, den der Anbieter durch erneute Zustellung ausgleichen muss.
        Schema::rename('webhook_events', 'webhook_events_nicht_erreichbar');

        try {
            $this->sendeWebhook($this->erfolgsnutzlast($vorgang['zahlung']))->assertStatus(500);
        } finally {
            Schema::rename('webhook_events_nicht_erreichbar', 'webhook_events');
        }

        self::assertSame(PaymentStatus::AUSSTEHEND, Payment::query()
            ->findOrFail($vorgang['zahlung']->getKey())
            ->getAttribute('status'));
    }

    public function test_eine_gescheiterte_finalisierung_wird_bei_erneuter_erfolgsmeldung_nachgeholt(): void
    {
        $vorgang = $this->offenerCheckout();

        $this->stoereArtefaktspeicher();
        $this->sendeWebhook($this->erfolgsnutzlast($vorgang['zahlung']))->assertOk();

        /** @var BillingRun $lauf */
        $lauf = BillingRun::query()->findOrFail($vorgang['lauf']->getKey());

        self::assertSame(BillingRunStatus::FAILED, $lauf->getAttribute('status'));
        self::assertNotNull($lauf->getAttribute('paid_at'));
        self::assertSame(PaymentStatus::BEZAHLT, Payment::query()
            ->findOrFail($vorgang['zahlung']->getKey())
            ->getAttribute('status'));

        /** @var User $nutzer */
        $nutzer = User::query()->findOrFail($vorgang['zahlung']->getAttribute('user_id'));

        // Die Warteseite sagt dem Kunden ehrlich, was los ist.
        $antwort = $this->actingAs($nutzer)
            ->get(route('portal.checkout.erfolg', ['billingRun' => $lauf->getKey()]));

        $antwort->assertOk();
        $antwort->assertSee('Zahlung bestätigt, Erstellung verzögert');
        $antwort->assertSee('nicht erneut zahlen');
        $antwort->assertDontSee('Bestätigung steht noch aus');

        // Der Speicher ist wieder da, der Anbieter meldet den Erfolg erneut
        // unter neuer Ereigniskennung.
        $this->stelleArtefaktspeicherWiederHer();

        $this->sendeWebhook($this->erfolgsnutzlast($vorgang['zahlung']))->assertJson(['status' => 'verarbeitet']);

        self::assertSame(BillingRunStatus::FINALIZED, BillingRun::query()
            ->findOrFail($lauf->getKey())
            ->getAttribute('status'));
        self::assertSame(1, Invoice::query()->count());
    }

    public function test_die_gescheiterte_finalisierung_wird_ueber_den_konsolenbefehl_nachgeholt(): void
    {
        $vorgang = $this->offenerCheckout();

        $this->stoereArtefaktspeicher();
        $this->sendeWebhook($this->erfolgsnutzlast($vorgang['zahlung']))->assertOk();

        self::assertSame(BillingRunStatus::FAILED, BillingRun::query()
            ->findOrFail($vorgang['lauf']->getKey())
            ->getAttribute('status'));
        self::assertCount(1, app(PaymentRecoveryOverview::class)->unfinalizedPaidRuns());

        // Solange die Ursache besteht, meldet der Befehl den offenen Fall.
        $this->artisan('smartabrechnen:retry-finalization')->assertFailed();

        $this->stelleArtefaktspeicherWiederHer();

        $this->artisan('smartabrechnen:retry-finalization')->assertSuccessful();

        self::assertSame(BillingRunStatus::FINALIZED, BillingRun::query()
            ->findOrFail($vorgang['lauf']->getKey())
            ->getAttribute('status'));
        self::assertSame(1, Invoice::query()->count());
        self::assertSame([], app(PaymentRecoveryOverview::class)->unfinalizedPaidRuns());

        // Beide Versuche, der gescheiterte und der erfolgreiche, sind protokolliert.
        self::assertSame(2, AuditLog::query()->where('action', 'billing_run.finalization_retried')->count());
    }

    public function test_verwaiste_abrechnungen_fruehrer_berechnungsstaende_werden_nicht_bezahlt_und_ersetzt(): void
    {
        $this->bestaetigteBetreiberstammdaten();
        $daten = $this->vorschaubereiterLauf(2);

        // Eine Abrechnung eines frueheren Standes, deren Mietverhaeltnis im
        // aktuellen Ergebnis nicht mehr vorkommt, blieb in BERECHNET stehen.
        /** @var CalculationSnapshot $alterStand */
        $alterStand = CalculationSnapshot::factory()->create([
            'organization_id' => $daten['organization']->getKey(),
            'billing_run_id' => $daten['billingRun']->getKey(),
            'version_number' => 0,
        ]);

        /** @var UnitStatement $verwaist */
        $verwaist = UnitStatement::factory()->create([
            'organization_id' => $daten['organization']->getKey(),
            'billing_run_id' => $daten['billingRun']->getKey(),
            'calculation_snapshot_id' => $alterStand->getKey(),
            'status' => UnitStatementStatus::BERECHNET,
        ]);

        $this->actingAs($daten['user'])
            ->post(route('portal.checkout.store', ['billingRun' => $daten['billingRun']->getKey()]), [
                'sofortige_ausfuehrung' => '1',
                'vertragsgrundlagen' => '1',
            ])
            ->assertRedirect();

        /** @var Payment $zahlung */
        $zahlung = Payment::query()->where('billing_run_id', $daten['billingRun']->getKey())->firstOrFail();

        // Bezahlt werden zwei Abrechnungen, nicht drei.
        self::assertSame(2, (int) $zahlung->getAttribute('statement_count'));
        self::assertSame(2 * 2490, (int) $zahlung->getAttribute('amount_cent'));

        $this->bindeFinalDocumentViews(new FinalViewBundle(
            [PdfFixtures::statementView(), PdfFixtures::statementView()],
            [null, null],
            PdfFixtures::ownerOverviewView(),
        ));

        $this->sendeWebhook($this->erfolgsnutzlast($zahlung))->assertJson(['status' => 'verarbeitet']);

        self::assertSame(UnitStatementStatus::ERSETZT, UnitStatement::query()
            ->findOrFail($verwaist->getKey())
            ->getAttribute('status'));
        self::assertSame(2, UnitStatement::query()
            ->where('billing_run_id', $daten['billingRun']->getKey())
            ->where('status', UnitStatementStatus::FINAL->value)
            ->count());

        /** @var Invoice $rechnung */
        $rechnung = Invoice::query()->firstOrFail();

        self::assertSame(2 * 2490, (int) $rechnung->getAttribute('gross_cent'));
    }

    public function test_die_verspaetete_ablaufmeldung_einer_alten_sitzung_setzt_den_laufenden_checkout_nicht_zurueck(): void
    {
        $vorgang = $this->offenerCheckout();

        /** @var User $nutzer */
        $nutzer = User::query()->findOrFail($vorgang['zahlung']->getAttribute('user_id'));

        // Der Betreiber aendert den Preis, der Kunde sendet das Formular
        // erneut: alter Vorgang abgebrochen, neuer Vorgang mit neuer Sitzung.
        config()->set('smartabrechnen.pricing.per_statement_gross_cent', 2590);

        $this->actingAs($nutzer)
            ->post(route('portal.checkout.store', ['billingRun' => $vorgang['lauf']->getKey()]), [
                'sofortige_ausfuehrung' => '1',
                'vertragsgrundlagen' => '1',
            ])
            ->assertRedirect();

        self::assertSame(2, Payment::query()->where('billing_run_id', $vorgang['lauf']->getKey())->count());

        // Die Ablaufmeldung der alten Sitzung trifft ein.
        $this->sendeWebhook($this->nutzlast('checkout.session.expired', [
            'id' => (string) $vorgang['zahlung']->getAttribute('checkout_session_id'),
            'metadata' => ['payment_id' => (string) $vorgang['zahlung']->getKey()],
        ]))->assertJson(['status' => 'verarbeitet']);

        self::assertSame(BillingRunStatus::CHECKOUT_PENDING, BillingRun::query()
            ->findOrFail($vorgang['lauf']->getKey())
            ->getAttribute('status'));
        self::assertSame(PaymentStatus::ABGEBROCHEN, Payment::query()
            ->findOrFail($vorgang['zahlung']->getKey())
            ->getAttribute('status'));

        // Der neue Vorgang wird bezahlt und schaltet frei.
        /** @var Payment $neu */
        $neu = Payment::query()
            ->where('billing_run_id', $vorgang['lauf']->getKey())
            ->where('status', PaymentStatus::AUSSTEHEND->value)
            ->firstOrFail();

        $this->sendeWebhook($this->erfolgsnutzlast($neu))->assertJson(['status' => 'verarbeitet']);

        self::assertSame(BillingRunStatus::FINALIZED, BillingRun::query()
            ->findOrFail($vorgang['lauf']->getKey())
            ->getAttribute('status'));
    }

    public function test_eine_doppelt_zugestellte_meldung_wird_idempotent_verarbeitet(): void
    {
        $vorgang = $this->offenerCheckout();
        $payload = $this->erfolgsnutzlast($vorgang['zahlung'], [], 'evt_test_idempotent_1');

        $this->sendeWebhook($payload)->assertJson(['status' => 'verarbeitet']);
        $this->sendeWebhook($payload)->assertJson(['status' => 'ignoriert']);

        self::assertSame(1, WebhookEvent::query()->where('provider_event_id', 'evt_test_idempotent_1')->count());

        /** @var WebhookEvent $eintrag */
        $eintrag = WebhookEvent::query()->where('provider_event_id', 'evt_test_idempotent_1')->firstOrFail();

        self::assertSame(2, (int) $eintrag->getAttribute('attempts'));
        self::assertSame(WebhookProcessingStatus::VERARBEITET, $eintrag->getAttribute('processing_status'));

        // Die Zahlung bleibt einmal bezahlt, es entsteht keine zweite Rechnung.
        self::assertSame(1, Payment::query()->where('status', PaymentStatus::BEZAHLT->value)->count());
        self::assertSame(1, Invoice::query()->count());
    }

    public function test_der_browser_redirect_allein_schaltet_nicht_frei(): void
    {
        $daten = $this->vorschaubereiterLauf(2);

        $this->actingAs($daten['user'])
            ->post(route('portal.checkout.store', ['billingRun' => $daten['billingRun']->getKey()]), [
                'sofortige_ausfuehrung' => '1',
                'vertragsgrundlagen' => '1',
            ])
            ->assertRedirect();

        // Der Nutzer kehrt aus der Zahlungsseite zurueck, ohne dass eine
        // Rueckmeldung des Anbieters vorliegt.
        $antwort = $this->actingAs($daten['user'])
            ->get(route('portal.checkout.erfolg', ['billingRun' => $daten['billingRun']->getKey()]));

        $antwort->assertOk();
        $antwort->assertSee('Bestätigung steht noch aus');

        /** @var BillingRun $lauf */
        $lauf = BillingRun::query()->findOrFail($daten['billingRun']->getKey());

        self::assertSame(BillingRunStatus::CHECKOUT_PENDING, $lauf->getAttribute('status'));
        self::assertNull($lauf->getAttribute('paid_at'));
        self::assertNull($lauf->getAttribute('finalized_at'));
        // Die Vorschaudokumente des Fixtures zaehlen nicht; es darf kein Finaldokument entstehen.
        self::assertSame(0, GeneratedDocument::query()->where('variant', GeneratedDocumentVariant::FINAL->value)->count());
        self::assertSame(0, Invoice::query()->count());
    }

    public function test_eine_abgelaufene_zahlung_laesst_den_lauf_in_der_vorschau(): void
    {
        $vorgang = $this->offenerCheckout();

        $payload = $this->nutzlast('checkout.session.expired', [
            'id' => (string) $vorgang['zahlung']->getAttribute('checkout_session_id'),
            'metadata' => ['payment_id' => (string) $vorgang['zahlung']->getKey()],
        ]);

        $this->sendeWebhook($payload)->assertJson(['status' => 'verarbeitet']);

        /** @var Payment $zahlung */
        $zahlung = Payment::query()->findOrFail($vorgang['zahlung']->getKey());
        /** @var BillingRun $lauf */
        $lauf = BillingRun::query()->findOrFail($vorgang['lauf']->getKey());

        self::assertSame(PaymentStatus::ABGELAUFEN, $zahlung->getAttribute('status'));
        self::assertNotNull($zahlung->getAttribute('expired_at'));
        self::assertSame(BillingRunStatus::PREVIEW_READY, $lauf->getAttribute('status'));
        self::assertNull($lauf->getAttribute('paid_at'));
    }

    public function test_ein_abgelehnter_zahlversuch_laesst_die_sitzung_offen_und_den_lauf_im_checkout(): void
    {
        $vorgang = $this->offenerCheckout();

        // Abgelehnte Karte: die Zahlungsabsicht traegt die Kennungen aus
        // payment_intent_data.metadata, die Sitzung bleibt bezahlbar.
        $payload = $this->nutzlast('payment_intent.payment_failed', [
            'id' => 'pi_test_fehlgeschlagen',
            'metadata' => ['payment_id' => (string) $vorgang['zahlung']->getKey()],
        ]);

        $this->sendeWebhook($payload)->assertJson(['status' => 'verarbeitet']);

        /** @var Payment $zahlung */
        $zahlung = Payment::query()->findOrFail($vorgang['zahlung']->getKey());

        self::assertSame(PaymentStatus::AUSSTEHEND, $zahlung->getAttribute('status'));
        self::assertSame('ZAHLVERSUCH_FEHLGESCHLAGEN', (string) $zahlung->getAttribute('failure_code'));
        self::assertSame(BillingRunStatus::CHECKOUT_PENDING, BillingRun::query()
            ->findOrFail($vorgang['lauf']->getKey())
            ->getAttribute('status'));

        // Der zweite Versuch in derselben Sitzung gelingt und schaltet frei.
        $this->sendeWebhook($this->erfolgsnutzlast($vorgang['zahlung']))->assertJson(['status' => 'verarbeitet']);

        self::assertSame(BillingRunStatus::FINALIZED, BillingRun::query()
            ->findOrFail($vorgang['lauf']->getKey())
            ->getAttribute('status'));
    }

    public function test_das_endgueltige_scheitern_einer_asynchronen_zahlart_laesst_den_lauf_in_der_vorschau(): void
    {
        $vorgang = $this->offenerCheckout();

        $payload = $this->nutzlast('checkout.session.async_payment_failed', [
            'id' => (string) $vorgang['zahlung']->getAttribute('checkout_session_id'),
            'metadata' => ['payment_id' => (string) $vorgang['zahlung']->getKey()],
        ]);

        $this->sendeWebhook($payload)->assertJson(['status' => 'verarbeitet']);

        self::assertSame(PaymentStatus::FEHLGESCHLAGEN, Payment::query()
            ->findOrFail($vorgang['zahlung']->getKey())
            ->getAttribute('status'));
        self::assertSame(BillingRunStatus::PREVIEW_READY, BillingRun::query()
            ->findOrFail($vorgang['lauf']->getKey())
            ->getAttribute('status'));
    }

    public function test_eine_erstattung_wird_behandelt_und_aendert_den_lauf_nicht(): void
    {
        $vorgang = $this->offenerCheckout();
        $this->sendeWebhook($this->erfolgsnutzlast($vorgang['zahlung']))->assertOk();

        $payload = $this->nutzlast('charge.refunded', [
            'id' => 'ch_test_erstattung',
            'amount' => (int) $vorgang['zahlung']->getAttribute('amount_cent'),
            'amount_refunded' => (int) $vorgang['zahlung']->getAttribute('amount_cent'),
            'currency' => 'eur',
            'metadata' => ['payment_id' => (string) $vorgang['zahlung']->getKey()],
        ]);

        $this->sendeWebhook($payload)->assertJson(['status' => 'verarbeitet']);

        /** @var Payment $zahlung */
        $zahlung = Payment::query()->findOrFail($vorgang['zahlung']->getKey());

        self::assertSame(PaymentStatus::ERSTATTET, $zahlung->getAttribute('status'));
        self::assertNotNull($zahlung->getAttribute('refunded_at'));

        // FINALIZED bleibt Endzustand, die Rechnung wird nicht ueberschrieben.
        self::assertSame(BillingRunStatus::FINALIZED, BillingRun::query()
            ->findOrFail($vorgang['lauf']->getKey())
            ->getAttribute('status'));
        self::assertSame(1, Invoice::query()->count());
    }

    public function test_eine_teilerstattung_wird_gesondert_vermerkt(): void
    {
        $vorgang = $this->offenerCheckout();
        $this->sendeWebhook($this->erfolgsnutzlast($vorgang['zahlung']))->assertOk();

        $payload = $this->nutzlast('charge.refunded', [
            'id' => 'ch_test_teilerstattung',
            'amount' => (int) $vorgang['zahlung']->getAttribute('amount_cent'),
            'amount_refunded' => 1000,
            'currency' => 'eur',
            'metadata' => ['payment_id' => (string) $vorgang['zahlung']->getKey()],
        ]);

        $this->sendeWebhook($payload)->assertOk();

        /** @var Payment $zahlung */
        $zahlung = Payment::query()->findOrFail($vorgang['zahlung']->getKey());

        self::assertSame(PaymentStatus::TEILWEISE_ERSTATTET, $zahlung->getAttribute('status'));
        self::assertSame(1000, (int) $zahlung->getAttribute('refunded_amount_cent'));
    }

    public function test_eine_rueckbelastung_wird_behandelt(): void
    {
        $vorgang = $this->offenerCheckout();
        $this->sendeWebhook($this->erfolgsnutzlast($vorgang['zahlung']))->assertOk();

        $payload = $this->nutzlast('charge.dispute.created', [
            'id' => 'dp_test_rueckbelastung',
            'amount' => (int) $vorgang['zahlung']->getAttribute('amount_cent'),
            'currency' => 'eur',
            'metadata' => ['payment_id' => (string) $vorgang['zahlung']->getKey()],
        ]);

        $this->sendeWebhook($payload)->assertJson(['status' => 'verarbeitet']);

        /** @var Payment $zahlung */
        $zahlung = Payment::query()->findOrFail($vorgang['zahlung']->getKey());

        self::assertSame(PaymentStatus::ANGEFOCHTEN, $zahlung->getAttribute('status'));
        self::assertNotNull($zahlung->getAttribute('dispute_opened_at'));
    }

    public function test_eine_meldung_ohne_zuordenbare_zahlung_wird_ignoriert(): void
    {
        $payload = $this->nutzlast('checkout.session.completed', [
            'id' => 'cs_test_unbekannt',
            'amount_total' => 2490,
            'currency' => 'eur',
            'payment_status' => 'paid',
        ]);

        $this->sendeWebhook($payload)->assertJson(['status' => 'ignoriert']);

        /** @var WebhookEvent $eintrag */
        $eintrag = WebhookEvent::query()->where('event_type', 'checkout.session.completed')->firstOrFail();

        self::assertSame('ZAHLUNG_NICHT_GEFUNDEN', $eintrag->getAttribute('error_code'));
    }

    public function test_eine_nicht_behandelte_ereignisart_wird_ignoriert(): void
    {
        $vorgang = $this->offenerCheckout();

        $payload = $this->nutzlast('payment_intent.created', [
            'id' => 'pi_test_erzeugt',
            'metadata' => ['payment_id' => (string) $vorgang['zahlung']->getKey()],
        ]);

        $this->sendeWebhook($payload)->assertJson(['status' => 'ignoriert']);

        self::assertSame(PaymentStatus::AUSSTEHEND, Payment::query()
            ->findOrFail($vorgang['zahlung']->getKey())
            ->getAttribute('status'));
    }

    public function test_die_gespeicherte_nutzlast_ist_datensparsam(): void
    {
        $vorgang = $this->offenerCheckout();
        $this->sendeWebhook($this->erfolgsnutzlast($vorgang['zahlung']))->assertOk();

        /** @var WebhookEvent $eintrag */
        $eintrag = WebhookEvent::query()->where('event_type', 'checkout.session.completed')->firstOrFail();

        $nutzlast = $eintrag->getAttribute('payload');

        self::assertIsString($nutzlast);

        $entschluesselt = json_decode($nutzlast, true);

        self::assertIsArray($entschluesselt);
        self::assertSame([
            'id',
            'client_reference_id',
            'payment_intent',
            'amount_cent',
            'currency',
            'payment_status',
            'billing_run_id',
            'payment_id',
        ], array_keys($entschluesselt));
        self::assertSame(64, strlen((string) $eintrag->getAttribute('payload_digest')));
    }

    public function test_die_webhook_route_verlangt_kein_csrf_token_und_keine_anmeldung(): void
    {
        $vorgang = $this->offenerCheckout();

        // Ohne Anmeldung und ohne Token, ausschliesslich mit gueltiger Signatur.
        $this->sendeWebhook($this->erfolgsnutzlast($vorgang['zahlung']))->assertOk();

        self::assertSame(PaymentStatus::BEZAHLT, Payment::query()
            ->findOrFail($vorgang['zahlung']->getKey())
            ->getAttribute('status'));
    }
}
