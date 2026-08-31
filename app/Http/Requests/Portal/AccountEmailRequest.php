<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use App\Http\Requests\GermanFormRequest;
use Illuminate\Validation\Rule;

/**
 * Aenderung der E-Mail-Adresse.
 *
 * Die Aenderung erfordert die Eingabe des aktuellen Passworts und setzt die
 * Bestaetigung zurueck. An die neue Adresse geht ein neuer Bestaetigungslink.
 * Bis zur Bestaetigung sind Zahlung und finaler Download gesperrt
 * (Masterprompt 8.1).
 */
class AccountEmailRequest extends GermanFormRequest
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
        $user = $this->user();

        return [
            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:190',
                Rule::unique('users', 'email')->ignore($user?->getKey()),
            ],
            'current_password' => ['required', 'string', 'current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => 'Neue E-Mail-Adresse',
            'current_password' => 'Aktuelles Passwort',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function eigeneMeldungen(): array
    {
        return [
            'email.unique' => 'Diese E-Mail-Adresse kann nicht verwendet werden. Bitte wählen Sie eine andere.',
        ];
    }
}
