<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use App\Http\Requests\GermanFormRequest;

/**
 * Aenderung der E-Mail-Adresse.
 *
 * Die Aenderung erfordert die Eingabe des aktuellen Passworts und setzt die
 * Bestaetigung zurueck. An die neue Adresse geht ein neuer Bestaetigungslink.
 * Bis zur Bestaetigung sind Zahlung und finaler Download gesperrt
 * (Masterprompt 8.1).
 *
 * KEINE KONTOERKENNUNG: Die Regel unique auf users.email ist bewusst nicht
 * gesetzt. Sie haette verraten, ob zu einer Adresse ein Konto besteht. Die
 * Entscheidung faellt im Controller mit identischer Antwort in beiden
 * Faellen, wie bei der Registrierung.
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
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
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
}
