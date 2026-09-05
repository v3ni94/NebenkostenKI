<?php

declare(strict_types=1);

namespace App\Http\Requests\Wizard;

use App\Http\Requests\GermanFormRequest;

/**
 * Schritt 9: ausdrückliche Nutzerentscheidung zu einer Warnung.
 *
 * Ohne Entscheidungstext bleibt die Warnung offen. Blocker und nicht
 * wegklickbare Regeln lassen sich auf diesem Weg nicht auflösen.
 */
class DecideWarningRequest extends GermanFormRequest
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
            'entscheidung' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['entscheidung' => 'Ihre Entscheidung'];
    }

    public function entscheidung(): string
    {
        $wert = $this->input('entscheidung');

        return is_string($wert) ? trim($wert) : '';
    }
}
