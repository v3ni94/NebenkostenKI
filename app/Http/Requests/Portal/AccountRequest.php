<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use App\Enums\OrganizationType;
use App\Http\Requests\GermanFormRequest;
use Illuminate\Validation\Rule;

/**
 * Name und Rechnungsanschrift des Kontos.
 *
 * DATENSPARSAMKEIT: Es werden ausschliesslich die Angaben erfasst, die fuer die
 * HVM-Leistungsrechnung erforderlich sind. Eine Steuernummer wird nicht
 * erhoben, die USt-IdNr. nur freiwillig fuer Unternehmen. Bankdaten des Kunden
 * werden hier ausdruecklich nicht erfasst, die Zahlung laeuft ueber den
 * Zahlungsanbieter.
 */
class AccountRequest extends GermanFormRequest
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
            'name' => ['required', 'string', 'max:190'],
            'organization_name' => ['required', 'string', 'max:190'],
            'organization_type' => ['required', Rule::enum(OrganizationType::class)],
            'billing_name' => ['nullable', 'string', 'max:190'],
            'billing_address_line' => ['nullable', 'string', 'max:190'],
            'billing_address_extra' => ['nullable', 'string', 'max:190'],
            'billing_postal_code' => ['nullable', 'string', 'max:16'],
            'billing_city' => ['nullable', 'string', 'max:120'],
            'vat_id' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'Name',
            'organization_name' => 'Bezeichnung des Kontos',
            'organization_type' => 'Art des Kontos',
            'billing_name' => 'Rechnungsempfänger',
            'billing_address_line' => 'Rechnungsanschrift, Straße und Hausnummer',
            'billing_address_extra' => 'Rechnungsanschrift, Adresszusatz',
            'billing_postal_code' => 'Rechnungsanschrift, Postleitzahl',
            'billing_city' => 'Rechnungsanschrift, Ort',
            'vat_id' => 'Umsatzsteuer-Identifikationsnummer',
        ];
    }
}
