<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\GermanFormRequest;

/**
 * Storno einer Leistungsrechnung (Masterprompt 15.2, 20).
 *
 * VERBINDLICH
 *
 *  1. Ein Storno ist ausschliesslich nach Freigabe durch die Geschaeftsfuehrung
 *     auszuloesen. Die Freigabe ist hier ausdruecklich zu bestaetigen.
 *  2. Die Begruendung ist Pflicht und wird im Revisionsprotokoll gespeichert.
 *  3. Ein Storno ueberschreibt nichts. Es entsteht eine eigene Stornorechnung
 *     mit eigener Nummer, eigenem Beleg und Referenz auf die Ursprungsrechnung.
 */
class InvoiceCancellationRequest extends GermanFormRequest
{
    public const int BEGRUENDUNG_MINDESTLAENGE = 15;

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
            'freigabe_geschaeftsfuehrung' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'grund' => 'Begründung',
            'freigabe_geschaeftsfuehrung' => 'Freigabe der Geschäftsführung',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function eigeneMeldungen(): array
    {
        return [
            'grund.required' => 'Bitte begründen Sie das Storno. Die Begründung wird protokolliert.',
            'grund.min' => 'Bitte begründen Sie das Storno nachvollziehbar, mindestens '
                .self::BEGRUENDUNG_MINDESTLAENGE.' Zeichen.',
            'freigabe_geschaeftsfuehrung.accepted' => 'Ein Storno ist nur nach Freigabe durch die '
                .'Geschäftsführung zulässig. Bitte bestätigen Sie die Freigabe ausdrücklich.',
        ];
    }

    public function grund(): string
    {
        return trim((string) $this->string('grund'));
    }
}
