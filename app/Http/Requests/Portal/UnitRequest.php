<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use App\Http\Requests\GermanFormRequest;
use App\Models\Unit;
use Illuminate\Validation\Rule;

/**
 * Anlage und Bearbeitung einer Einheit.
 *
 * Erfasst werden Flaeche, beheizte Flaeche, Miteigentumsanteil und die
 * individuellen Schluesselwerte 1 bis 5. Die Bezeichnungen der individuellen
 * Schluessel liegen am Objekt, die Werte je Einheit.
 *
 * Fehlende Werte bleiben null und erzeugen einen Hinweis. Es wird niemals
 * geschaetzt (ARCHITECTURE.md Grundsatz 5).
 */
class UnitRequest extends GermanFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(DecimalInput::normalize($this->all(), [
            'living_area_sqm',
            'heated_area_sqm',
            'mea',
            'individual_key_1_value',
            'individual_key_2_value',
            'individual_key_3_value',
            'individual_key_4_value',
            'individual_key_5_value',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => [
                'required',
                'string',
                'max:120',
                // Der Unique-Index units(property_id, label) darf nicht erst in
                // der Datenbank greifen. Weich geloeschte Einheiten bleiben
                // ausser Betracht; sie werden beim Wiederanlegen derselben
                // Bezeichnung im Controller wiederhergestellt.
                Rule::unique('units', 'label')
                    ->where('property_id', $this->propertyId())
                    ->whereNull('deleted_at')
                    ->ignore($this->unitId()),
            ],
            'location' => ['nullable', 'string', 'max:190'],
            'unit_number' => ['nullable', 'string', 'max:60'],
            'living_area_sqm' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'heated_area_sqm' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'mea' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'room_count' => ['nullable', 'integer', 'min:0', 'max:99'],
            'individual_key_1_value' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'individual_key_2_value' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'individual_key_3_value' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'individual_key_4_value' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'individual_key_5_value' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'is_commercial' => ['nullable', 'boolean'],
            'is_owner_occupied' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'label' => 'Bezeichnung der Einheit',
            'location' => 'Lage',
            'unit_number' => 'Wohnungsnummer',
            'living_area_sqm' => 'Wohnfläche',
            'heated_area_sqm' => 'Beheizte Fläche',
            'mea' => 'Miteigentumsanteil',
            'room_count' => 'Anzahl der Zimmer',
            'individual_key_1_value' => 'Individueller Schlüssel 1',
            'individual_key_2_value' => 'Individueller Schlüssel 2',
            'individual_key_3_value' => 'Individueller Schlüssel 3',
            'individual_key_4_value' => 'Individueller Schlüssel 4',
            'individual_key_5_value' => 'Individueller Schlüssel 5',
            'is_commercial' => 'Gewerbliche Nutzung',
            'is_owner_occupied' => 'Selbst genutzt',
            'notes' => 'Notiz',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function eigeneMeldungen(): array
    {
        return [
            'label.required' => 'Bitte geben Sie eine Bezeichnung für die Einheit an, zum Beispiel WE 3.',
            'label.unique' => 'Diese Bezeichnung ist in diesem Objekt bereits vergeben. Bitte wählen Sie eine andere.',
        ];
    }

    /**
     * Kennung der zu bearbeitenden Einheit, sonst null (Anlage).
     */
    public function unitId(): ?string
    {
        $unit = $this->route('unit');

        return is_string($unit) && $unit !== '' ? $unit : null;
    }

    /**
     * Objekt, in dem die Bezeichnung eindeutig sein muss. Bei der Anlage
     * kommt es aus der Route, bei der Bearbeitung aus der Einheit.
     */
    public function propertyId(): string
    {
        $property = $this->route('property');

        if (is_string($property) && $property !== '') {
            return $property;
        }

        $unitId = $this->unitId();

        if ($unitId === null) {
            return '';
        }

        $propertyId = Unit::query()->whereKey($unitId)->value('property_id');

        return is_string($propertyId) ? $propertyId : '';
    }
}
