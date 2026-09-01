<?php

declare(strict_types=1);

namespace App\Http\Requests\Checkout;

use App\Application\Payment\Dto\CheckoutConsent;
use App\Http\Requests\GermanFormRequest;

/**
 * Einleitung der Zahlung (Schritt 11, Abschnitt 2.3).
 *
 * VERBINDLICH:
 *  - Beide Kaestchen sind Pflicht und im Formular NICHT vorangekreuzt.
 *  - Es wird KEIN Betrag und KEINE Anzahl aus dem Formular gelesen. Ein
 *    mitgesendetes Preisfeld ist ohne Wirkung: Der Preis wird ausschliesslich
 *    serverseitig neu berechnet (ADR-010). Deshalb gibt es hier bewusst keine
 *    Regel fuer ein Betragsfeld; ein solcher Wert wird nie ausgewertet.
 */
class StartCheckoutRequest extends GermanFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sofortige_ausfuehrung' => ['accepted'],
            'vertragsgrundlagen' => ['accepted'],
        ];
    }

    public function consent(): CheckoutConsent
    {
        return new CheckoutConsent(
            $this->boolean('sofortige_ausfuehrung'),
            $this->boolean('vertragsgrundlagen'),
        );
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'sofortige_ausfuehrung' => 'Zustimmung zur sofortigen Vertragsausführung',
            'vertragsgrundlagen' => 'Bestätigung der Vertragsgrundlagen',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function eigeneMeldungen(): array
    {
        return [
            'sofortige_ausfuehrung.accepted' => 'Bitte bestätigen Sie die sofortige Ausführung des Vertrags. '
                .'Ohne diese Bestätigung kann die Zahlung nicht eingeleitet werden.',
            'vertragsgrundlagen.accepted' => 'Bitte bestätigen Sie die Allgemeinen Geschäftsbedingungen, '
                .'die Datenschutzerklärung und die Widerrufsbelehrung.',
        ];
    }
}
