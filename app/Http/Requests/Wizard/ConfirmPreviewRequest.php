<?php

declare(strict_types=1);

namespace App\Http\Requests\Wizard;

use App\Http\Requests\GermanFormRequest;

/**
 * Schritt 10: Bestätigung vor dem Checkout.
 *
 * Die Checkbox ist ausdrücklich NICHT vorangekreuzt und Pflicht. Ohne
 * Bestätigung ist kein Checkout möglich.
 */
class ConfirmPreviewRequest extends GermanFormRequest
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
            'bestaetigung' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'bestaetigung.accepted' => 'Bitte bestätigen Sie, dass Sie alle Daten und Ergebnisse geprüft haben, '
                .'die Verantwortung als Vermieter übernehmen, Preis und Anzahl der Abrechnungen verstanden haben '
                .'und die rechtlichen Pflichttexte akzeptieren.',
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['bestaetigung' => 'Bestätigung'];
    }
}
