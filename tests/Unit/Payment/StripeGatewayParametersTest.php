<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Services\Payment\Dto\CheckoutSessionPayload;
use App\Services\Payment\StripeConfiguration;
use App\Services\Payment\StripeGateway;
use Tests\TestCase;

/**
 * Welche Felder tatsaechlich an den Zahlungsanbieter gehen (Abschnitt 15.1).
 *
 * Der Test ruft ausschliesslich die Aufbereitung der Parameter auf. Es entsteht
 * kein Netzverkehr, und es wird kein Schluessel verwendet.
 */
final class StripeGatewayParametersTest extends TestCase
{
    private function payload(int $grundpreisCent = 0): CheckoutSessionPayload
    {
        return new CheckoutSessionPayload(
            'Betriebskostenabrechnung 2025, 3 Mieterabrechnungen',
            3,
            2490,
            $grundpreisCent,
            'eur',
            '01LAUF00000000000000000000',
            [
                'billing_run_id' => '01LAUF00000000000000000000',
                'payment_id' => '01ZAHLUNG000000000000000000',
            ],
            'https://smart-abrechnen.test/app/abrechnungen/1/zahlung/erfolg',
            'https://smart-abrechnen.test/app/abrechnungen/1/zahlung/abbruch',
            'idem-0000-0000',
            'kunde@beispiel.test',
        );
    }

    public function test_es_wird_eine_einmalzahlung_und_kein_abonnement_angelegt(): void
    {
        $parameter = (new StripeGateway(StripeConfiguration::of(null, null)))->parameters($this->payload());

        self::assertSame('payment', $parameter['mode']);
        self::assertArrayNotHasKey('subscription_data', $parameter);
        self::assertArrayNotHasKey('recurring', $parameter);
    }

    public function test_betrag_menge_und_leistungsbezeichnung_stammen_aus_dem_payload(): void
    {
        $parameter = (new StripeGateway(StripeConfiguration::of(null, null)))->parameters($this->payload());

        self::assertIsArray($parameter['line_items']);
        self::assertCount(1, $parameter['line_items']);

        $position = $parameter['line_items'][0];

        self::assertSame(3, $position['quantity']);
        self::assertSame(2490, $position['price_data']['unit_amount']);
        self::assertSame('eur', $position['price_data']['currency']);
        self::assertSame(
            'Betriebskostenabrechnung 2025, 3 Mieterabrechnungen',
            $position['price_data']['product_data']['name'],
        );
    }

    public function test_der_grundpreis_wird_als_eigene_position_uebertragen(): void
    {
        $parameter = (new StripeGateway(StripeConfiguration::of(null, null)))->parameters($this->payload(1000));

        self::assertCount(2, $parameter['line_items']);
        self::assertSame(1000, $parameter['line_items'][1]['price_data']['unit_amount']);
        self::assertSame(1, $parameter['line_items'][1]['quantity']);
    }

    public function test_die_sitzung_wird_an_lauf_und_zahlung_gebunden(): void
    {
        $parameter = (new StripeGateway(StripeConfiguration::of(null, null)))->parameters($this->payload());

        self::assertSame('01LAUF00000000000000000000', $parameter['client_reference_id']);
        self::assertSame('01LAUF00000000000000000000', $parameter['metadata']['billing_run_id']);
        self::assertSame('01ZAHLUNG000000000000000000', $parameter['metadata']['payment_id']);
    }

    public function test_es_werden_ausschliesslich_die_vorgesehenen_felder_uebertragen(): void
    {
        $parameter = (new StripeGateway(StripeConfiguration::of(null, null)))->parameters($this->payload());

        self::assertSame([
            'mode',
            'line_items',
            'client_reference_id',
            'metadata',
            'success_url',
            'cancel_url',
            'locale',
            'customer_email',
        ], array_keys($parameter));

        $uebertragen = json_encode($parameter, JSON_UNESCAPED_UNICODE);

        self::assertIsString($uebertragen);

        // Die Anzahl der Mieterabrechnungen ist eine Mengenangabe und kein
        // Mieterdatum. Verboten sind Vertrags-, Beleg- und Objektangaben.
        foreach (['Mietvertrag', 'Beleg', 'Grundsteuer', 'Hausgeld', 'Wohnung', 'Rosenstraße'] as $verboten) {
            self::assertStringNotContainsString($verboten, $uebertragen);
        }
    }

    public function test_ohne_email_adresse_entfaellt_das_feld(): void
    {
        $payload = new CheckoutSessionPayload(
            'Betriebskostenabrechnung 2025, 1 Mieterabrechnung',
            1,
            2490,
            0,
            'eur',
            '01LAUF00000000000000000000',
            [],
            'https://smart-abrechnen.test/erfolg',
            'https://smart-abrechnen.test/abbruch',
            'idem-0000-0001',
        );

        $parameter = (new StripeGateway(StripeConfiguration::of(null, null)))->parameters($payload);

        self::assertArrayNotHasKey('customer_email', $parameter);
    }
}
