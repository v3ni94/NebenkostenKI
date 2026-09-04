<?php

declare(strict_types=1);

namespace App\Http\Requests\Privacy;

use App\Http\Requests\GermanFormRequest;

/**
 * Antrag auf Löschung des Kontos.
 *
 * Die ausdrückliche Bestätigung ist verbindlich, damit ein Fehlklick keinen
 * Löschantrag auslöst. Zusätzlich ist das aktuelle Passwort einzugeben: Eine
 * übernommene oder unbeaufsichtigte Sitzung darf die Löschung des Kontos
 * nicht anstoßen können, genauso wie sie die E-Mail-Adresse nicht ändern
 * kann. Die Autorisierung selbst liegt in der Policy des Controllers.
 */
final class DeletionRequestRequest extends GermanFormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'bestaetigung' => ['accepted'],
            'current_password' => ['required', 'string', 'current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function eigeneMeldungen(): array
    {
        return [
            'bestaetigung.accepted' => 'Bitte bestätigen Sie, dass Sie die Löschung Ihres Kontos beantragen möchten.',
            'current_password.required' => 'Bitte geben Sie zur Bestätigung Ihr aktuelles Passwort ein.',
            'current_password.current_password' => 'Das eingegebene Passwort ist nicht richtig.',
        ];
    }
}
