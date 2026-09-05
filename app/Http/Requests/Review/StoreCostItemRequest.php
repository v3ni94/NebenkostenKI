<?php

declare(strict_types=1);

namespace App\Http\Requests\Review;

/**
 * Manuelle Anlage einer Kostenposition in der Pruefung.
 *
 * Auch eine manuell erfasste Position ist zunaechst nur vorgeschlagen. Die
 * Bestaetigung bleibt eine eigene Handlung.
 */
class StoreCostItemRequest extends UpdateCostItemRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'invoice_number' => ['nullable', 'string', 'max:80'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function daten(): array
    {
        return array_merge(parent::daten(), [
            'invoice_number' => $this->input('invoice_number'),
        ]);
    }
}
