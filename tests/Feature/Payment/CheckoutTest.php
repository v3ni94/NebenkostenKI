<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\BillingRunStatus;
use App\Enums\CostItemStatus;
use App\Enums\LegalDocumentPurpose;
use App\Enums\PaymentStatus;
use App\Models\BillingRun;
use App\Models\CostItem;
use App\Models\LegalAcceptance;
use App\Models\Payment;
use App\Services\Payment\Contracts\CheckoutClient;
use App\Services\Payment\Dto\CheckoutSessionPayload;
use App\Services\Payment\Dto\CheckoutSessionResult;
use App\Services\Payment\Exceptions\CheckoutProviderException;
use App\Services\Payment\Exceptions\PaymentConfigurationException;

/**
 * Schritt 11: Zahlung einleiten (Abschnitt 15.1, 2.3, ADR-010).
 */
final class CheckoutTest extends PaymentTestCase
{
    public function test_die_zahlungsseite_weist_netto_steuer_und_brutto_getrennt_aus(): void
    {
        $daten = $this->vorschaubereiterLauf(3);

        $antwort = $this->actingAs($daten['user'])
            ->get(route('portal.checkout.show', ['billingRun' => $daten['billingRun']->getKey()]));

        $antwort->assertOk();
        $antwort->assertSee('74,70 EUR');
        // 3 mal 20,92 EUR netto, Steuer als Differenz zum Brutto.
        $antwort->assertSee('62,76 EUR');
        $antwort->assertSee('11,94 EUR');
        $antwort->assertSee('Umsatzsteuer 19 Prozent');
        $antwort->assertSee('Erzeugte Mieterabrechnungen');
    }

    public function test_die_zahlungsseite_zeigt_beide_nicht_vorangekreuzten_kaestchen(): void
    {
        $daten = $this->vorschaubereiterLauf(2);

        $antwort = $this->actingAs($daten['user'])
            ->get(route('portal.checkout.show', ['billingRun' => $daten['billingRun']->getKey()]));

        $antwort->assertOk();
        $antwort->assertSee('name="sofortige_ausfuehrung"', false);
        $antwort->assertSee('name="vertragsgrundlagen"', false);
        $antwort->assertDontSee('checked', false);
        $antwort->assertSee('VOR LIVEGANG DURCH RECHTSANWALT PRÜFEN UND FREIGEBEN');
    }

    public function test_der_preis_wird_serverseitig_neu_berechnet_und_ein_formularwert_ignoriert(): void
    {
        $daten = $this->vorschaubereiterLauf(3);

        $this->actingAs($daten['user'])
            ->post(route('portal.checkout.store', ['billingRun' => $daten['billingRun']->getKey()]), [
                'sofortige_ausfuehrung' => '1',
                'vertragsgrundlagen' => '1',
                // Manipulierte Werte. Sie werden nicht ausgewertet.
                'betrag_cent' => 1,
                'amount_cent' => 1,
                'anzahl' => 1,
                'statement_count' => 1,
                'preis' => '0,01',
            ])
            ->assertRedirect();

        /** @var Payment $zahlung */
        $zahlung = Payment::query()->where('billing_run_id', $daten['billingRun']->getKey())->firstOrFail();

        self::assertSame(3 * 2490, (int) $zahlung->getAttribute('amount_cent'));
        self::assertSame(3, (int) $zahlung->getAttribute('statement_count'));

        $payload = $this->checkoutClient->lastPayload();

        self::assertNotNull($payload);
        self::assertSame(3, $payload->quantity);
        self::assertSame(2490, $payload->unitAmountGrossCent);
        self::assertSame(7470, $payload->totalGrossCent());
    }

    public function test_ohne_widerrufs_kaestchen_wird_der_checkout_abgelehnt(): void
    {
        $daten = $this->vorschaubereiterLauf(2);

        $antwort = $this->actingAs($daten['user'])
            ->from(route('portal.checkout.show', ['billingRun' => $daten['billingRun']->getKey()]))
            ->post(route('portal.checkout.store', ['billingRun' => $daten['billingRun']->getKey()]), [
                'vertragsgrundlagen' => '1',
            ]);

        $antwort->assertSessionHasErrors(['sofortige_ausfuehrung']);

        self::assertSame(0, Payment::query()->count());
        self::assertSame([], $this->checkoutClient->payloads);
    }

    public function test_ohne_bestaetigung_der_vertragsgrundlagen_wird_der_checkout_abgelehnt(): void
    {
        $daten = $this->vorschaubereiterLauf(2);

        $antwort = $this->actingAs($daten['user'])
            ->from(route('portal.checkout.show', ['billingRun' => $daten['billingRun']->getKey()]))
            ->post(route('portal.checkout.store', ['billingRun' => $daten['billingRun']->getKey()]), [
                'sofortige_ausfuehrung' => '1',
            ]);

        $antwort->assertSessionHasErrors(['vertragsgrundlagen']);

        self::assertSame(0, Payment::query()->count());
    }

    public function test_ohne_pruefbestaetigung_des_nutzers_wird_der_checkout_abgelehnt(): void
    {
        $daten = $this->vorschaubereiterLauf(2, bestaetigt: false);

        $antwort = $this->actingAs($daten['user'])
            ->from(route('portal.checkout.show', ['billingRun' => $daten['billingRun']->getKey()]))
            ->post(route('portal.checkout.store', ['billingRun' => $daten['billingRun']->getKey()]), [
                'sofortige_ausfuehrung' => '1',
                'vertragsgrundlagen' => '1',
            ]);

        $antwort->assertSessionHasErrors(['zahlung']);

        self::assertSame(0, Payment::query()->count());
        self::assertSame([], $this->checkoutClient->payloads);
    }

    /**
     * Zweite Verteidigungslinie (Befund N9): Eine Stammdatenaenderung nach der
     * Bestaetigung macht die Vorschau ungueltig; StartCheckout verweigert den
     * Checkout mit Hinweis auf die Vorschau, auch wenn der Lauf im Zustand
     * PREVIEW_READY bleibt.
     */
    public function test_ohne_gueltige_vorschau_wird_der_checkout_abgelehnt(): void
    {
        // Eine Abrechnung, damit das Mietverhaeltnis ohne Ueberschneidung
        // bearbeitet werden kann.
        $daten = $this->vorschaubereiterLauf(1);

        $this->actingAs($daten['user'])->put(
            route('portal.mietverhaeltnisse.update', ['tenancy' => $daten['tenancy']->getKey()]),
            [
                'tenant_display_name' => (string) $daten['tenancy']->getAttribute('tenant_display_name'),
                'kind' => 'WOHNRAUM',
                'starts_on' => '2025-01-01',
                'monthly_operating_prepayment_eur' => '210,00',
            ]
        )->assertRedirect()->assertSessionHasNoErrors();

        // Die Bestaetigung wurde durch die Aenderung zurueckgenommen. Sie wird
        // hier absichtlich wieder gesetzt, damit allein die fehlende Vorschau
        // den Checkout sperrt.
        $daten['billingRun']->forceFill([
            'review_confirmed_at' => now(),
            'responsibility_confirmed_at' => now(),
        ])->save();

        self::assertSame(BillingRunStatus::PREVIEW_READY, $daten['billingRun']->refresh()->getAttribute('status'));

        $antwort = $this->actingAs($daten['user'])
            ->from(route('portal.checkout.show', ['billingRun' => $daten['billingRun']->getKey()]))
            ->post(route('portal.checkout.store', ['billingRun' => $daten['billingRun']->getKey()]), [
                'sofortige_ausfuehrung' => '1',
                'vertragsgrundlagen' => '1',
            ]);

        $antwort->assertRedirect(route('portal.checkout.show', ['billingRun' => $daten['billingRun']->getKey()]));
        $antwort->assertSessionHasErrors(['zahlung']);

        $fehler = session('errors')?->get('zahlung') ?? [];

        self::assertStringContainsString('keine gültige Vorschau', implode(' ', $fehler));
        self::assertSame(0, Payment::query()->count());
        self::assertSame([], $this->checkoutClient->payloads);

        // Die Zahlungsseite zeigt den Hinweis.
        $this->actingAs($daten['user'])
            ->get(route('portal.checkout.show', ['billingRun' => $daten['billingRun']->getKey()]))
            ->assertOk()
            ->assertSee('keine gültige Vorschau');
    }

    public function test_ein_offener_sperrgrund_verhindert_den_checkout(): void
    {
        $daten = $this->vorschaubereiterLauf(2);

        // Eine nachtraeglich vorgeschlagene, noch nicht entschiedene Position.
        CostItem::factory()->create([
            'organization_id' => $daten['organization']->getKey(),
            'billing_run_id' => $daten['billingRun']->getKey(),
            'status' => CostItemStatus::VORGESCHLAGEN,
            'confirmed_at' => null,
        ]);

        $antwort = $this->actingAs($daten['user'])
            ->from(route('portal.checkout.show', ['billingRun' => $daten['billingRun']->getKey()]))
            ->post(route('portal.checkout.store', ['billingRun' => $daten['billingRun']->getKey()]), [
                'sofortige_ausfuehrung' => '1',
                'vertragsgrundlagen' => '1',
            ]);

        $antwort->assertSessionHasErrors(['zahlung']);

        $fehler = session('errors')?->get('zahlung') ?? [];

        self::assertStringContainsString('Kostenpositionen offen', implode(' ', $fehler));
        self::assertSame(0, Payment::query()->count());
    }

    public function test_die_zustimmungen_landen_datensparsam_in_legal_acceptances(): void
    {
        $daten = $this->vorschaubereiterLauf(1);

        $this->actingAs($daten['user'])
            ->post(route('portal.checkout.store', ['billingRun' => $daten['billingRun']->getKey()]), [
                'sofortige_ausfuehrung' => '1',
                'vertragsgrundlagen' => '1',
            ])
            ->assertRedirect();

        $zustimmungen = LegalAcceptance::query()
            ->where('billing_run_id', $daten['billingRun']->getKey())
            ->get();

        self::assertCount(2, $zustimmungen);

        $zwecke = $zustimmungen
            ->map(static fn (LegalAcceptance $eintrag): string => (string) $eintrag->getAttribute('purpose')->value)
            ->all();

        self::assertContains(LegalDocumentPurpose::SOFORTIGE_VERTRAGSAUSFUEHRUNG->value, $zwecke);
        self::assertContains(LegalDocumentPurpose::AGB->value, $zwecke);

        /** @var LegalAcceptance $erste */
        $erste = $zustimmungen->first();

        self::assertSame('2026-01-ENTWURF', $erste->getAttribute('document_version'));
        self::assertSame(64, strlen((string) $erste->getAttribute('document_hash')));
        self::assertNotNull($erste->getAttribute('accepted_at'));
        self::assertSame('127.0.0.0', $erste->getAttribute('ip_truncated'));
    }

    public function test_der_lauf_wechselt_in_den_zustand_checkout_pending(): void
    {
        $daten = $this->vorschaubereiterLauf(2);

        $this->actingAs($daten['user'])
            ->post(route('portal.checkout.store', ['billingRun' => $daten['billingRun']->getKey()]), [
                'sofortige_ausfuehrung' => '1',
                'vertragsgrundlagen' => '1',
            ])
            ->assertRedirect();

        /** @var BillingRun $lauf */
        $lauf = BillingRun::query()->findOrFail($daten['billingRun']->getKey());

        self::assertSame(BillingRunStatus::CHECKOUT_PENDING, $lauf->getAttribute('status'));
        self::assertSame(4980, (int) $lauf->getAttribute('price_total_gross_cent'));
        self::assertSame(2490, (int) $lauf->getAttribute('price_per_statement_gross_cent'));
        self::assertNotNull($lauf->getAttribute('price_quoted_at'));
    }

    public function test_der_zahlungsvorgang_wird_an_lauf_nutzer_und_mandant_gebunden(): void
    {
        $daten = $this->vorschaubereiterLauf(2);

        $this->actingAs($daten['user'])
            ->post(route('portal.checkout.store', ['billingRun' => $daten['billingRun']->getKey()]), [
                'sofortige_ausfuehrung' => '1',
                'vertragsgrundlagen' => '1',
            ])
            ->assertRedirect();

        $payload = $this->checkoutClient->lastPayload();

        self::assertNotNull($payload);
        self::assertSame((string) $daten['billingRun']->getKey(), $payload->clientReferenceId);
        self::assertSame((string) $daten['billingRun']->getKey(), $payload->metadata['billing_run_id']);
        self::assertSame((string) $daten['user']->getKey(), $payload->metadata['user_id']);
        self::assertSame((string) $daten['organization']->getKey(), $payload->metadata['organization_id']);
        self::assertArrayHasKey('payment_id', $payload->metadata);
        self::assertNotSame('', $payload->idempotencyKey);
    }

    public function test_ein_doppelt_abgesendetes_formular_erzeugt_keinen_zweiten_vorgang(): void
    {
        $daten = $this->vorschaubereiterLauf(2);

        for ($versuch = 0; $versuch < 2; $versuch++) {
            $this->actingAs($daten['user'])
                ->post(route('portal.checkout.store', ['billingRun' => $daten['billingRun']->getKey()]), [
                    'sofortige_ausfuehrung' => '1',
                    'vertragsgrundlagen' => '1',
                ])
                ->assertRedirect();
        }

        self::assertSame(1, Payment::query()->where('billing_run_id', $daten['billingRun']->getKey())->count());

        $schluessel = Payment::query()
            ->where('billing_run_id', $daten['billingRun']->getKey())
            ->value('idempotency_key');

        self::assertNotNull($schluessel);
        self::assertSame($schluessel, $this->checkoutClient->payloads[0]->idempotencyKey);
        self::assertSame($schluessel, $this->checkoutClient->payloads[1]->idempotencyKey);
    }

    public function test_die_uebertragenen_felder_enthalten_keine_mieter_oder_belegdaten(): void
    {
        $daten = $this->vorschaubereiterLauf(2);

        $daten['tenancy']->forceFill(['tenant_display_name' => 'Frau Beispielmieterin'])->save();

        $this->actingAs($daten['user'])
            ->post(route('portal.checkout.store', ['billingRun' => $daten['billingRun']->getKey()]), [
                'sofortige_ausfuehrung' => '1',
                'vertragsgrundlagen' => '1',
            ])
            ->assertRedirect();

        $payload = $this->checkoutClient->lastPayload();

        self::assertNotNull($payload);

        $uebertragen = json_encode($payload, JSON_UNESCAPED_UNICODE);
        self::assertIsString($uebertragen);

        // Neutrale Leistungsbezeichnung ohne Mieter, Objekt oder Beleg.
        self::assertSame('Betriebskostenabrechnung 2025, 2 Mieterabrechnungen', $payload->productName);

        foreach ([
            'Beispielmieterin',
            (string) $daten['property']->getAttribute('label'),
            (string) $daten['unit']->getAttribute('label'),
            'Mietvertrag',
            'Beleg',
            'Grundsteuer',
        ] as $verboten) {
            if ($verboten === '') {
                continue;
            }

            self::assertStringNotContainsString($verboten, $uebertragen, sprintf(
                'Der Zahlungsanbieter darf die Angabe "%s" nicht erhalten.',
                $verboten,
            ));
        }

        // Uebertragen werden nur technische Kennungen.
        self::assertSame(
            ['billing_run_id', 'payment_id', 'organization_id', 'statement_count', 'user_id'],
            array_keys($payload->metadata),
        );
    }

    public function test_ein_bezahlter_lauf_laesst_keinen_zweiten_checkout_zu(): void
    {
        $daten = $this->vorschaubereiterLauf(2);
        $this->bezahlterLauf($daten['billingRun'], 4980, 2);

        $antwort = $this->actingAs($daten['user'])
            ->from(route('portal.checkout.show', ['billingRun' => $daten['billingRun']->getKey()]))
            ->post(route('portal.checkout.store', ['billingRun' => $daten['billingRun']->getKey()]), [
                'sofortige_ausfuehrung' => '1',
                'vertragsgrundlagen' => '1',
            ]);

        // Die Policy laesst einen bezahlten Lauf nicht mehr in den Checkout.
        $antwort->assertForbidden();
        self::assertSame([], $this->checkoutClient->payloads);
    }

    public function test_der_abbruch_laesst_den_lauf_in_der_vorschau(): void
    {
        $daten = $this->vorschaubereiterLauf(2);

        $this->actingAs($daten['user'])
            ->post(route('portal.checkout.store', ['billingRun' => $daten['billingRun']->getKey()]), [
                'sofortige_ausfuehrung' => '1',
                'vertragsgrundlagen' => '1',
            ])
            ->assertRedirect();

        $this->actingAs($daten['user'])
            ->delete(route('portal.checkout.destroy', ['billingRun' => $daten['billingRun']->getKey()]))
            ->assertRedirect(route('portal.checkout.show', ['billingRun' => $daten['billingRun']->getKey()]));

        /** @var BillingRun $lauf */
        $lauf = BillingRun::query()->findOrFail($daten['billingRun']->getKey());
        /** @var Payment $zahlung */
        $zahlung = Payment::query()->where('billing_run_id', $lauf->getKey())->firstOrFail();

        self::assertSame(BillingRunStatus::PREVIEW_READY, $lauf->getAttribute('status'));
        self::assertSame(PaymentStatus::ABGEBROCHEN, $zahlung->getAttribute('status'));
        self::assertNotSame([], $this->checkoutClient->expiredSessions);
    }

    public function test_ein_preis_ausserhalb_des_korridors_zeigt_eine_meldung_statt_eines_serverfehlers(): void
    {
        config()->set('smartabrechnen.pricing.per_statement_gross_cent', 1990);

        $daten = $this->vorschaubereiterLauf(2);

        $antwort = $this->actingAs($daten['user'])
            ->get(route('portal.checkout.show', ['billingRun' => $daten['billingRun']->getKey()]));

        $antwort->assertOk();
        $antwort->assertSee('außerhalb des freigegebenen Korridors');
        $antwort->assertDontSee('Kostenpflichtig zahlen');

        // Auch das Absenden leitet keine Zahlung ein.
        $this->actingAs($daten['user'])
            ->from(route('portal.checkout.show', ['billingRun' => $daten['billingRun']->getKey()]))
            ->post(route('portal.checkout.store', ['billingRun' => $daten['billingRun']->getKey()]), [
                'sofortige_ausfuehrung' => '1',
                'vertragsgrundlagen' => '1',
            ])
            ->assertSessionHasErrors(['zahlung']);

        self::assertSame([], $this->checkoutClient->payloads);
    }

    public function test_ein_fehler_des_zahlungsanbieters_erreicht_den_nutzer_als_verstaendliche_meldung(): void
    {
        $daten = $this->vorschaubereiterLauf(2);

        $this->app->instance(CheckoutClient::class, new class implements CheckoutClient
        {
            public function createCheckoutSession(CheckoutSessionPayload $payload): CheckoutSessionResult
            {
                throw CheckoutProviderException::sessionNotCreated();
            }

            public function expireCheckoutSession(string $sessionId): void {}
        });

        $antwort = $this->actingAs($daten['user'])
            ->from(route('portal.checkout.show', ['billingRun' => $daten['billingRun']->getKey()]))
            ->post(route('portal.checkout.store', ['billingRun' => $daten['billingRun']->getKey()]), [
                'sofortige_ausfuehrung' => '1',
                'vertragsgrundlagen' => '1',
            ]);

        $antwort->assertRedirect(route('portal.checkout.show', ['billingRun' => $daten['billingRun']->getKey()]));
        $antwort->assertSessionHasErrors(['zahlung']);

        self::assertStringContainsString(
            'Die Zahlungsseite konnte nicht angelegt werden',
            (string) session('errors')?->first('zahlung'),
        );

        // Der Lauf bleibt in der Vorschau, es wurde nichts freigeschaltet.
        self::assertSame(BillingRunStatus::PREVIEW_READY, BillingRun::query()
            ->findOrFail($daten['billingRun']->getKey())
            ->getAttribute('status'));
    }

    public function test_eine_unvollstaendige_zahlungsanbindung_erreicht_den_nutzer_als_verstaendliche_meldung(): void
    {
        $daten = $this->vorschaubereiterLauf(2);

        $this->app->instance(CheckoutClient::class, new class implements CheckoutClient
        {
            public function createCheckoutSession(CheckoutSessionPayload $payload): CheckoutSessionResult
            {
                throw PaymentConfigurationException::missing('STRIPE_SECRET');
            }

            public function expireCheckoutSession(string $sessionId): void {}
        });

        $antwort = $this->actingAs($daten['user'])
            ->from(route('portal.checkout.show', ['billingRun' => $daten['billingRun']->getKey()]))
            ->post(route('portal.checkout.store', ['billingRun' => $daten['billingRun']->getKey()]), [
                'sofortige_ausfuehrung' => '1',
                'vertragsgrundlagen' => '1',
            ]);

        $antwort->assertSessionHasErrors(['zahlung']);

        // Der Name der Umgebungsvariable gehoert nicht auf die Kundenseite.
        self::assertStringNotContainsString('STRIPE_SECRET', (string) session('errors')?->first('zahlung'));
    }

    public function test_ohne_rechnungsanschrift_wird_der_checkout_abgelehnt(): void
    {
        $daten = $this->vorschaubereiterLauf(2);

        $daten['organization']->forceFill([
            'billing_address_line' => null,
            'billing_postal_code' => null,
            'billing_city' => null,
        ])->save();

        $seite = $this->actingAs($daten['user'])
            ->get(route('portal.checkout.show', ['billingRun' => $daten['billingRun']->getKey()]));

        $seite->assertOk();
        $seite->assertSee('Rechnungsanschrift fehlt noch');
        $seite->assertSee(route('portal.konto.edit'));

        $antwort = $this->actingAs($daten['user'])
            ->from(route('portal.checkout.show', ['billingRun' => $daten['billingRun']->getKey()]))
            ->post(route('portal.checkout.store', ['billingRun' => $daten['billingRun']->getKey()]), [
                'sofortige_ausfuehrung' => '1',
                'vertragsgrundlagen' => '1',
            ]);

        $antwort->assertSessionHasErrors(['zahlung']);

        self::assertSame(0, Payment::query()->count());
        self::assertSame([], $this->checkoutClient->payloads);
    }

    public function test_der_abbruch_beendet_alle_offenen_zahlungsvorgaenge_des_laufs(): void
    {
        $daten = $this->vorschaubereiterLauf(2);

        $this->actingAs($daten['user'])
            ->post(route('portal.checkout.store', ['billingRun' => $daten['billingRun']->getKey()]), [
                'sofortige_ausfuehrung' => '1',
                'vertragsgrundlagen' => '1',
            ])
            ->assertRedirect();

        // Ein zweiter offener Vorgang, wie er bei zwei gleichzeitigen
        // Absendungen entstehen kann.
        Payment::factory()->create([
            'organization_id' => $daten['organization']->getKey(),
            'billing_run_id' => $daten['billingRun']->getKey(),
            'user_id' => $daten['user']->getKey(),
            'checkout_session_id' => 'cs_test_zweiter_vorgang',
            'amount_cent' => 4980,
            'statement_count' => 2,
            'status' => PaymentStatus::AUSSTEHEND,
        ]);

        $this->actingAs($daten['user'])
            ->delete(route('portal.checkout.destroy', ['billingRun' => $daten['billingRun']->getKey()]))
            ->assertRedirect();

        self::assertSame(0, Payment::query()
            ->where('billing_run_id', $daten['billingRun']->getKey())
            ->whereIn('status', [PaymentStatus::ERSTELLT->value, PaymentStatus::AUSSTEHEND->value])
            ->count());
        self::assertCount(2, $this->checkoutClient->expiredSessions);
        self::assertContains('cs_test_zweiter_vorgang', $this->checkoutClient->expiredSessions);
    }

    public function test_die_kundenansicht_nennt_keine_fehlenden_betreiberangaben(): void
    {
        // Betreiberstammdaten nicht bestaetigt: der Blocker ist eine interne
        // Angabe des Betriebs und gehoert nicht auf die Kundenseite.
        $daten = $this->vorschaubereiterLauf(2);

        $antwort = $this->actingAs($daten['user'])
            ->get(route('portal.checkout.show', ['billingRun' => $daten['billingRun']->getKey()]));

        $antwort->assertOk();
        $antwort->assertDontSee('Steuernummer');
        $antwort->assertDontSee('IBAN');
        $antwort->assertDontSee('blockiert');
        $antwort->assertSee('wird Ihnen nachgereicht');
    }

    public function test_ohne_bestaetigte_email_adresse_ist_die_zahlung_gesperrt(): void
    {
        $daten = $this->vorschaubereiterLauf(2);
        $daten['user']->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($daten['user'])
            ->post(route('portal.checkout.store', ['billingRun' => $daten['billingRun']->getKey()]), [
                'sofortige_ausfuehrung' => '1',
                'vertragsgrundlagen' => '1',
            ])
            ->assertForbidden();

        self::assertSame(0, Payment::query()->count());
    }
}
