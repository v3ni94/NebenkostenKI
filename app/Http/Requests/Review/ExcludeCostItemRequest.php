<?php

declare(strict_types=1);

namespace App\Http\Requests\Review;

use App\Http\Requests\GermanFormRequest;

/**
 * Ausschluss einer Kostenposition von der Umlage.
 */
class ExcludeCostItemRequest extends GermanFormRequest
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
            'grund' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['grund' => 'Grund'];
    }
}
