<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\GermanFormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Vergabe eines neuen Passworts nach dem Zuruecksetzen.
 */
class NewPasswordRequest extends GermanFormRequest
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
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(12)->letters()->numbers(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => 'E-Mail-Adresse',
            'password' => 'Passwort',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function eigeneMeldungen(): array
    {
        return [
            'password.min' => 'Das Passwort muss mindestens 12 Zeichen lang sein.',
            'password.letters' => 'Das Passwort muss mindestens einen Buchstaben enthalten.',
            'password.numbers' => 'Das Passwort muss mindestens eine Ziffer enthalten.',
            'token.required' => 'Der Link ist unvollständig. Bitte fordern Sie einen neuen Link an.',
        ];
    }
}
