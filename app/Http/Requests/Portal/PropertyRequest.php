<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use App\Enums\PropertyKind;
use App\Http\Requests\GermanFormRequest;
use Illuminate\Validation\Rule;

/**
 * Anlage und Bearbeitung eines Objekts.
 *
 * Flaechen und Anteile werden als Dezimalzahlen mit Punkt oder Komma
 * angenommen und in prepareForValidation() auf die Punktschreibweise
 * normalisiert. Gerechnet wird niemals mit binaeren Gleitkommazahlen, die Werte
 * gehen als Zeichenkette in die Dezimalspalten (ARCHITECTURE.md Grundsatz 8).
 */
class PropertyRequest extends GermanFormRequest
{
    public function authorize(): bool
    {
        // Die Autorisierung erfolgt im Controller ueber die PropertyPolicy mit
        // Object-Level-Check. Hier wird bewusst nicht anhand einer URL-ID
        // entschieden.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(DecimalInput::normalize($this->all(), [
            'total_living_area_sqm',
            'total_heated_area_sqm',
            'mea_denominator',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:190'],
            'address_line' => ['required', 'string', 'max:190'],
            'address_extra' => ['nullable', 'string', 'max:190'],
            'postal_code' => ['required', 'string', 'max:16'],
            'city' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::enum(PropertyKind::class)],
            'weg_name' => ['nullable', 'string', 'max:190'],
            'total_living_area_sqm' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'total_heated_area_sqm' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'mea_denominator' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'individual_key_1_label' => ['nullable', 'string', 'max:120'],
            'individual_key_2_label' => ['nullable', 'string', 'max:120'],
            'individual_key_3_label' => ['nullable', 'string', 'max:120'],
            'individual_key_4_label' => ['nullable', 'string', 'max:120'],
            'individual_key_5_label' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'label' => 'Bezeichnung',
            'address_line' => 'Straße und Hausnummer',
            'address_extra' => 'Adresszusatz',
            'postal_code' => 'Postleitzahl',
            'city' => 'Ort',
            'kind' => 'Objektart',
            'weg_name' => 'Bezeichnung der Eigentümergemeinschaft',
            'total_living_area_sqm' => 'Gesamtwohnfläche',
            'total_heated_area_sqm' => 'Beheizte Gesamtfläche',
            'mea_denominator' => 'Nenner der Miteigentumsanteile',
            'notes' => 'Notiz',
        ];
    }
}
