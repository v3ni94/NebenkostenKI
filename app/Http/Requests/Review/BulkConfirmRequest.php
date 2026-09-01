<?php

declare(strict_types=1);

namespace App\Http\Requests\Review;

use App\Http\Requests\GermanFormRequest;

/**
 * Sammelbestaetigung.
 *
 * Die uebergebenen Kennungen sind nur ein Wunsch. Die Anwendung bestaetigt
 * ausschliesslich Positionen, die sie selbst als konfliktfrei und
 * hochkonfident eingeordnet hat.
 */
class BulkConfirmRequest extends GermanFormRequest
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
            'kostenpositionen' => ['nullable', 'array'],
            'kostenpositionen.*' => ['string', 'max:26'],
        ];
    }

    /**
     * @return list<string>|null
     */
    public function kennungen(): ?array
    {
        $werte = $this->input('kostenpositionen');

        if (! is_array($werte) || $werte === []) {
            return null;
        }

        $ids = [];

        foreach ($werte as $wert) {
            if (is_string($wert) && $wert !== '') {
                $ids[] = $wert;
            }
        }

        return $ids === [] ? null : $ids;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['kostenpositionen' => 'Kostenpositionen'];
    }
}
