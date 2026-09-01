<?php

declare(strict_types=1);

namespace App\Http\Requests\Wizard;

use App\Enums\AllocationKeyType;
use App\Http\Requests\GermanFormRequest;

/**
 * Schritt 8: Verteilerschlüssel und Werte je Beteiligtem.
 *
 * Werte bleiben Zeichenketten und werden erst in der Anwendungsschicht mit
 * brick/math verarbeitet (ADR-004).
 */
class StoreAllocationKeysRequest extends GermanFormRequest
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
            'kostenarten' => ['required', 'array'],
            'kostenarten.*.key_type' => ['required', 'string', 'in:'.implode(',', $this->types())],
            'kostenarten.*.nenner' => ['nullable', 'string', 'max:30'],
            'kostenarten.*.masseinheit' => ['nullable', 'string', 'max:20'],
            'kostenarten.*.werte' => ['nullable', 'array'],
            'kostenarten.*.werte.*' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'kostenarten' => 'Kostenarten',
            'kostenarten.*.key_type' => 'Verteilerschlüssel',
            'kostenarten.*.nenner' => 'Nenner',
            'kostenarten.*.masseinheit' => 'Maßeinheit',
            'kostenarten.*.werte.*' => 'Wert je Einheit',
        ];
    }

    /**
     * @return array<string, array{key_type: string, nenner: string|null, masseinheit: string|null, werte: array<string, string|null>}>
     */
    public function eingaben(): array
    {
        $kostenarten = $this->input('kostenarten');
        $ergebnis = [];

        if (! is_array($kostenarten)) {
            return $ergebnis;
        }

        foreach ($kostenarten as $categoryId => $zeile) {
            if (! is_array($zeile)) {
                continue;
            }

            $werte = [];

            if (isset($zeile['werte']) && is_array($zeile['werte'])) {
                foreach ($zeile['werte'] as $participantId => $wert) {
                    $werte[(string) $participantId] = is_string($wert) ? $wert : null;
                }
            }

            $ergebnis[(string) $categoryId] = [
                'key_type' => is_string($zeile['key_type'] ?? null) ? $zeile['key_type'] : '',
                'nenner' => is_string($zeile['nenner'] ?? null) ? $zeile['nenner'] : null,
                'masseinheit' => is_string($zeile['masseinheit'] ?? null) ? $zeile['masseinheit'] : null,
                'werte' => $werte,
            ];
        }

        return $ergebnis;
    }

    /**
     * @return list<string>
     */
    private function types(): array
    {
        return array_map(static fn (AllocationKeyType $type): string => $type->value, AllocationKeyType::cases());
    }
}
