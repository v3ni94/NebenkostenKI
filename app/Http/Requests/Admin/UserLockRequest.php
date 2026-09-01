<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\GermanFormRequest;

/**
 * Sperren und Entsperren einer Kundenkennung (Masterprompt 20).
 *
 * Die Begruendung ist Pflicht und wird im Revisionsprotokoll gespeichert.
 */
class UserLockRequest extends GermanFormRequest
{
    public const int BEGRUENDUNG_MINDESTLAENGE = 10;

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
            'grund' => ['required', 'string', 'min:'.self::BEGRUENDUNG_MINDESTLAENGE, 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['grund' => 'Begründung'];
    }

    /**
     * @return array<string, string>
     */
    protected function eigeneMeldungen(): array
    {
        return [
            'grund.required' => 'Bitte begründen Sie die Änderung am Konto. Die Begründung wird protokolliert.',
            'grund.min' => 'Bitte begründen Sie die Änderung nachvollziehbar, mindestens '
                .self::BEGRUENDUNG_MINDESTLAENGE.' Zeichen.',
        ];
    }

    public function grund(): string
    {
        return trim((string) $this->string('grund'));
    }
}
