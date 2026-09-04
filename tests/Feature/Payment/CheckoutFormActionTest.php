<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Http\Middleware\SecurityHeaders;

/**
 * Wechselwirkung zwischen Bezahl-POST und Content Security Policy.
 *
 * Der Bezahlschritt ist ein gewoehnliches HTML-Formular. Die Antwort auf den
 * POST ist eine Weiterleitung auf die gehostete Zahlungsseite des Anbieters.
 * Chromium- und WebKit-Browser pruefen form-action der absendenden Seite auch
 * gegen das Ziel dieser Weiterleitung. Der Ursprung der Zahlungsseite muss
 * deshalb in form-action stehen, sonst bleibt der Nutzer ohne Zahlung auf der
 * Bezahlseite stehen.
 *
 * Ein HTTP-Test kann die browserseitige Sperre nicht selbst ausloesen. Geprueft
 * wird deshalb, dass die CSP der Zahlungsseite und der Antwort auf den POST
 * den produktiven Zahlungsanbieter-Ursprung in form-action fuehren.
 */
final class CheckoutFormActionTest extends PaymentTestCase
{
    public function test_die_zahlungsseite_erlaubt_die_weiterleitung_zum_zahlungsanbieter_in_form_action(): void
    {
        $daten = $this->vorschaubereiterLauf(2);

        $seite = $this->actingAs($daten['user'])
            ->get(route('portal.checkout.show', ['billingRun' => $daten['billingRun']->getKey()]));

        $seite->assertOk();
        self::assertContains(
            SecurityHeaders::ZAHLUNGSANBIETER_FORM_ACTION,
            $this->formActionQuellen((string) $seite->headers->get('Content-Security-Policy')),
        );
    }

    public function test_die_antwort_auf_den_bezahl_post_erlaubt_den_zahlungsanbieter_in_form_action(): void
    {
        $daten = $this->vorschaubereiterLauf(2);

        $antwort = $this->actingAs($daten['user'])
            ->post(route('portal.checkout.store', ['billingRun' => $daten['billingRun']->getKey()]), [
                'sofortige_ausfuehrung' => '1',
                'vertragsgrundlagen' => '1',
            ]);

        $antwort->assertRedirect();

        $ziel = (string) $antwort->headers->get('Location');
        $quellen = $this->formActionQuellen((string) $antwort->headers->get('Content-Security-Policy'));

        self::assertContains("'self'", $quellen);
        self::assertContains(SecurityHeaders::ZAHLUNGSANBIETER_FORM_ACTION, $quellen);

        // Die Weiterleitung fuehrt auf einen fremden Ursprung; genau dieser
        // Fall verlangt die Freigabe in form-action.
        self::assertNotSame(parse_url(config('app.url'), PHP_URL_HOST), parse_url($ziel, PHP_URL_HOST));

        // Der produktive Zahlungsanbieter liefert Session-URLs auf diesem Host.
        self::assertSame('https://checkout.stripe.com', SecurityHeaders::ZAHLUNGSANBIETER_FORM_ACTION);
    }

    /**
     * @return list<string>
     */
    private function formActionQuellen(string $csp): array
    {
        foreach (explode(';', $csp) as $direktive) {
            $teile = preg_split('/\s+/', trim($direktive)) ?: [];

            if (($teile[0] ?? '') === 'form-action') {
                return array_values(array_slice($teile, 1));
            }
        }

        self::fail('Die CSP enthaelt keine Direktive form-action.');
    }
}
