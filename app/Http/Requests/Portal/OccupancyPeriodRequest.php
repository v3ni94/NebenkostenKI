<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use App\Http\Requests\GermanFormRequest;

/**
 * Personenanzahl mit Gueltigkeitszeitraum.
 *
 * Grundlage der Verteilerschluessel Personen und Personentage. Aendert sich die
 * Personenanzahl waehrend des Mietverhaeltnisses, entsteht ein weiterer
 * Zeitraum. Ueberschneidungen innerhalb eines Mietverhaeltnisses werden im
 * Controller gegen den Bestand geprueft.
 */
class OccupancyPeriodRequest extends GermanFormRequest
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
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'person_count' => ['required', 'integer', 'min:0', 'max:99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'starts_on' => 'Beginn des Zeitraums',
            'ends_on' => 'Ende des Zeitraums',
            'person_count' => 'Anzahl der Personen',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function eigeneMeldungen(): array
    {
        return [
            'ends_on.after_or_equal' => 'Das Ende des Zeitraums darf nicht vor dem Beginn liegen.',
        ];
    }
}
