<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\GermanFormRequest;

/**
 * Pruefung eines geplanten Bruttopreises je Mieterabrechnung
 * (Masterprompt 1.3, 20).
 *
 * Der Preis wird gegen den zulaessigen Korridor aus
 * config('smartabrechnen.pricing.admin_range_gross_cent') geprueft. Eine
 * Aenderung wirkt ausschliesslich auf neue Berechnungsstaende.
 */
class PriceCheckRequest extends GermanFormRequest
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
        $range = config('smartabrechnen.pricing.admin_range_gross_cent');
        $min = is_array($range) && is_numeric($range['min'] ?? null) ? (int) $range['min'] : 2000;
        $max = is_array($range) && is_numeric($range['max'] ?? null) ? (int) $range['max'] : 3000;

        return [
            'preis_brutto_cent' => ['required', 'integer', 'min:'.$min, 'max:'.$max],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['preis_brutto_cent' => 'Bruttopreis je Mieterabrechnung in Cent'];
    }

    /**
     * @return array<string, string>
     */
    protected function eigeneMeldungen(): array
    {
        return [
            'preis_brutto_cent.min' => 'Der Preis liegt unterhalb des zulässigen Korridors.',
            'preis_brutto_cent.max' => 'Der Preis liegt oberhalb des zulässigen Korridors.',
        ];
    }

    public function preisCent(): int
    {
        return (int) $this->integer('preis_brutto_cent');
    }
}
