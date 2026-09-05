<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Application\Admin\SupportAccessGuard;
use App\Http\Requests\GermanFormRequest;

/**
 * Begruendung eines Supportzugriffs (Masterprompt 19, ARCHITECTURE.md T10).
 *
 * Ohne Begruendung gibt es keinen Einblick in Kundendaten. Die Begruendung
 * wird im Revisionsprotokoll gespeichert und muss aus sich heraus verstaendlich
 * sein, deshalb die Mindestlaenge.
 */
class SupportAccessRequest extends GermanFormRequest
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
            'grund' => ['required', 'string', 'min:'.SupportAccessGuard::BEGRUENDUNG_MINDESTLAENGE, 'max:500'],
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
            'grund.required' => 'Bitte geben Sie eine Begründung für den Supportzugriff an. '
                .'Ohne Begründung ist kein Einblick möglich.',
            'grund.min' => 'Bitte begründen Sie den Supportzugriff nachvollziehbar, mindestens '
                .SupportAccessGuard::BEGRUENDUNG_MINDESTLAENGE.' Zeichen.',
        ];
    }

    public function grund(): string
    {
        return trim((string) $this->string('grund'));
    }
}
