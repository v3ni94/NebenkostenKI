<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\BillingRunStatus;
use App\Enums\LegalDocumentPurpose;
use App\Enums\PaymentStatus;
use App\Models\BillingRun;
use App\Models\LegalAcceptance;
use App\Models\Payment;

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
        $antwort->assertSee('62,77 EUR');
        $antwort->assertSee('11,93 EUR');
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
