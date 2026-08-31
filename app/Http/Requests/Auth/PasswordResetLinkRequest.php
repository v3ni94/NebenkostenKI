<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\GermanFormRequest;

/**
 * Anforderung eines Links zum Zuruecksetzen des Passworts.
 *
 * Es wird ausdruecklich NICHT geprueft, ob die Adresse existiert. Die Antwort
 * ist immer gleich, damit die Seite kein Werkzeug zur Kontoerkennung wird.
 */
class PasswordResetLinkRequest extends GermanFormRequest
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => 'E-Mail-Adresse',
        ];
    }
}
