<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\GermanFormRequest;

/**
 * Eingabe eines Codes aus der Authenticator-App oder eines
 * Wiederherstellungscodes.
 *
 * Die Feldregeln bleiben absichtlich weit, damit die eigentliche Entscheidung
 * in der Anwendungsschicht faellt und die Fehlermeldung immer dieselbe ist. Eine
 * feinere Validierung wuerde verraten, welcher Codetyp erkannt wurde.
 */
class TwoFactorCodeRequest extends GermanFormRequest
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
            'code' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'Code',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function eigeneMeldungen(): array
    {
        return [
            'code.required' => 'Bitte geben Sie den Code aus Ihrer Authenticator-App ein.',
        ];
    }

    public function code(): string
    {
        return trim((string) $this->string('code'));
    }
}
