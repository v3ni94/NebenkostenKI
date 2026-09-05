<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use App\Http\Requests\GermanFormRequest;

/**
 * Erinnerungseinstellungen, global und je Objekt.
 *
 * Vorgabe des Masterprompts, Abschnitt 17: Erinnerungen im ersten, zweiten und
 * dritten Quartal sowie im Dezember. Der Nutzer kann sie global und je Objekt
 * abschalten. Die globale Einstellung wirkt als Obergrenze: ist sie aus,
 * erhaelt der Nutzer keine Erinnerung, unabhaengig von den Objekteinstellungen.
 */
class ReminderPreferenceRequest extends GermanFormRequest
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
            'global_active' => ['nullable', 'boolean'],
            'q1_enabled' => ['nullable', 'boolean'],
            'q2_enabled' => ['nullable', 'boolean'],
            'q3_enabled' => ['nullable', 'boolean'],
            'december_enabled' => ['nullable', 'boolean'],
            'objekte' => ['nullable', 'array'],
            'objekte.*' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'global_active' => 'Erinnerungen insgesamt',
            'q1_enabled' => 'Erinnerung im ersten Quartal',
            'q2_enabled' => 'Erinnerung im zweiten Quartal',
            'q3_enabled' => 'Erinnerung im dritten Quartal',
            'december_enabled' => 'Erinnerung im Dezember',
        ];
    }
}
