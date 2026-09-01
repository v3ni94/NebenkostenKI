<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Application\Payment\Dto\FinalViewBundle;
use App\Enums\BillingRunStatus;
use App\Enums\PaymentStatus;
use App\Enums\WebhookProcessingStatus;
use App\Enums\WebhookSignatureStatus;
use App\Models\BillingRun;
use App\Models\GeneratedDocument;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\WebhookEvent;
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
        self::assertSame(0, GeneratedDocument::query()->count());
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

    public function test_eine_fehlgeschlagene_zahlung_laesst_den_lauf_in_der_vorschau(): void
    {
        $vorgang = $this->offenerCheckout();

        $payload = $this->nutzlast('payment_intent.payment_failed', [
            'id' => 'pi_test_fehlgeschlagen',
            'metadata' => ['payment_id' => (string) $vorgang['zahlung']->getKey()],
        ]);

        $this->sendeWebhook($payload)->assertJson(['status' => 'verarbeitet']);

        /** @var Payment $zahlung */
        $zahlung = Payment::query()->findOrFail($vorgang['zahlung']->getKey());

        self::assertSame(PaymentStatus::FEHLGESCHLAGEN, $zahlung->getAttribute('status'));
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
