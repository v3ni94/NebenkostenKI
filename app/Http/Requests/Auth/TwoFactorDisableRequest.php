<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\GermanFormRequest;

/**
 * Abschaltung des Zweitfaktors.
 *
 * Verlangt beides: das aktuelle Passwort und einen gueltigen Faktor, also einen
 * Code aus der Authenticator-App oder einen Wiederherstellungscode. Ein
 * uebernommenes Gerät soll den Schutz nicht mit einem Klick entfernen koennen.
 */
class TwoFactorDisableRequest extends GermanFormRequest
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
            'current_password' => ['required', 'string', 'current_password'],
            'code' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'current_password' => 'Aktuelles Passwort',
            'code' => 'Code',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function eigeneMeldungen(): array
    {
        return [
            'code.required' => 'Bitte geben Sie einen Code aus Ihrer Authenticator-App oder einen '
                .'Wiederherstellungscode ein.',
        ];
    }

    public function code(): string
    {
        return trim((string) $this->string('code'));
    }
}
