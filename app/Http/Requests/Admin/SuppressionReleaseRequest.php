<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\GermanFormRequest;

/**
 * Aufheben einer Adresssperre der E-Mail-Sperrliste (Masterprompt 17.2, 20).
 *
 * Die Begruendung ist Pflicht und wird im Revisionsprotokoll gespeichert,
 * zum Beispiel "SMTP-Ausfall am 04.09.2026, keine echte Unzustellbarkeit".
 */
class SuppressionReleaseRequest extends GermanFormRequest
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
            'email' => ['required', 'string', 'email', 'max:190'],
            'grund' => ['required', 'string', 'min:'.self::BEGRUENDUNG_MINDESTLAENGE, 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['email' => 'E-Mail-Adresse', 'grund' => 'Begründung'];
    }

    /**
     * @return array<string, string>
     */
    protected function eigeneMeldungen(): array
    {
        return [
            'grund.required' => 'Bitte begründen Sie das Aufheben der Sperre. Die Begründung wird protokolliert.',
            'grund.min' => 'Bitte begründen Sie das Aufheben nachvollziehbar, mindestens '
                .self::BEGRUENDUNG_MINDESTLAENGE.' Zeichen.',
        ];
    }

    public function email(): string
    {
        return trim((string) $this->string('email'));
    }

    public function grund(): string
    {
        return trim((string) $this->string('grund'));
    }
}
