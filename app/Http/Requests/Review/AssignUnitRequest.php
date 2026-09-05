<?php

declare(strict_types=1);

namespace App\Http\Requests\Review;

use App\Http\Requests\GermanFormRequest;

/**
 * Direkte Zuordnung einer Kostenposition zu einer Einheit.
 */
class AssignUnitRequest extends GermanFormRequest
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
            'unit_id' => ['nullable', 'string', 'max:26'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['unit_id' => 'Einheit'];
    }
}
