<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use App\Http\Requests\GermanFormRequest;

/**
 * Leerstandszeitraum einer Einheit.
 *
 * Ein Leerstand ersetzt kein Mietverhaeltnis, sondern dokumentiert die Zeit
 * ohne Mieter. Er zaehlt bei der Zeitachse als Abdeckung, die Kosten bleiben
 * beim Eigentuemer.
 */
class VacancyPeriodRequest extends GermanFormRequest
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
            'reason' => ['nullable', 'string', 'max:190'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'starts_on' => 'Beginn des Leerstands',
            'ends_on' => 'Ende des Leerstands',
            'reason' => 'Grund',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function eigeneMeldungen(): array
    {
        return [
            'ends_on.after_or_equal' => 'Das Ende des Leerstands darf nicht vor dem Beginn liegen.',
        ];
    }
}
