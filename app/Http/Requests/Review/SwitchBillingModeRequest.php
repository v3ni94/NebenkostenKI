<?php

declare(strict_types=1);

namespace App\Http\Requests\Review;

use App\Enums\BillingMode;
use App\Http\Requests\GermanFormRequest;
use Illuminate\Validation\Rule;

/**
 * Wechsel des Abrechnungswegs.
 *
 * Ein Wechsel loescht keine ausgelesenen Inhaltsdaten. Die Positionen werden
 * neu eingeordnet.
 */
class SwitchBillingModeRequest extends GermanFormRequest
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
            'mode' => ['required', Rule::enum(BillingMode::class)],
        ];
    }

    public function modus(): BillingMode
    {
        $wert = $this->input('mode');

        return BillingMode::from(is_string($wert) ? $wert : BillingMode::QUICK_CONDO->value);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['mode' => 'Abrechnungsweg'];
    }
}
