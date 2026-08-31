<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use App\Enums\TenancyKind;
use App\Http\Requests\GermanFormRequest;
use Illuminate\Validation\Rule;

/**
 * Anlage und Bearbeitung eines Mietverhaeltnisses.
 *
 * Verbindliche Regeln (Masterprompt, Schritt 5):
 *
 *  - Auszugsdatum nicht vor dem Einzugsdatum.
 *  - Bei beendetem Mietverhaeltnis ist die Zustellanschrift Pflicht. Ohne sie
 *    kann die Abrechnung nicht zugestellt werden.
 *  - Ueberschneidungen mit bestehenden Mietverhaeltnissen und Leerstaenden
 *    derselben Einheit werden im Controller gegen die gespeicherte Zeitachse
 *    geprueft, weil dafuer der Datenbestand der Einheit benoetigt wird.
 */
class TenancyRequest extends GermanFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'monthly_operating_prepayment_eur' => DecimalInput::value(
                $this->input('monthly_operating_prepayment_eur')
            ),
            'monthly_heating_prepayment_eur' => DecimalInput::value(
                $this->input('monthly_heating_prepayment_eur')
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $beendet = $this->filled('ends_on');

        return [
            'tenant_display_name' => ['required', 'string', 'max:190'],
            'kind' => ['required', Rule::enum(TenancyKind::class)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],

            'delivery_address_line' => [$beendet ? 'required' : 'nullable', 'string', 'max:190'],
            'delivery_address_extra' => ['nullable', 'string', 'max:190'],
            'delivery_postal_code' => [$beendet ? 'required' : 'nullable', 'string', 'max:16'],
            'delivery_city' => [$beendet ? 'required' : 'nullable', 'string', 'max:120'],

            'monthly_operating_prepayment_eur' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'monthly_heating_prepayment_eur' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'heating_prepayment_separate' => ['nullable', 'boolean'],

            'operating_costs_apportionment_agreed' => ['nullable', 'in:0,1'],
            'other_operating_costs_agreed' => ['nullable', 'in:0,1'],

            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tenant_display_name' => 'Name des Mieters',
            'kind' => 'Art des Mietverhältnisses',
            'starts_on' => 'Einzug',
            'ends_on' => 'Auszug',
            'delivery_address_line' => 'Zustellanschrift, Straße und Hausnummer',
            'delivery_address_extra' => 'Zustellanschrift, Adresszusatz',
            'delivery_postal_code' => 'Zustellanschrift, Postleitzahl',
            'delivery_city' => 'Zustellanschrift, Ort',
            'monthly_operating_prepayment_eur' => 'Monatliche Vorauszahlung Betriebskosten',
            'monthly_heating_prepayment_eur' => 'Monatliche Vorauszahlung Heizkosten',
            'heating_prepayment_separate' => 'Heizkostenvorauszahlung getrennt vereinbart',
            'operating_costs_apportionment_agreed' => 'Umlage der Betriebskosten vereinbart',
            'other_operating_costs_agreed' => 'Sonstige Betriebskosten vereinbart',
            'notes' => 'Notiz',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function eigeneMeldungen(): array
    {
        return [
            'ends_on.after_or_equal' => 'Der Auszug darf nicht vor dem Einzug liegen.',
            'delivery_address_line.required' => 'Bei einem beendeten Mietverhältnis ist die Zustellanschrift erforderlich.',
            'delivery_postal_code.required' => 'Bei einem beendeten Mietverhältnis ist die Postleitzahl der Zustellanschrift erforderlich.',
            'delivery_city.required' => 'Bei einem beendeten Mietverhältnis ist der Ort der Zustellanschrift erforderlich.',
        ];
    }
}
