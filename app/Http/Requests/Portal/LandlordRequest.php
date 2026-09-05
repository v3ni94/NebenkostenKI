<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use App\Http\Requests\GermanFormRequest;

/**
 * Vermieter als Absender der Mieterabrechnung (Masterprompt 2.2, Schritt 4).
 *
 * Pflicht sind Name und Anschrift. Firma, Kontakt und Bankverbindung sind
 * freiwillig. IBAN und BIC werden ohne Leerzeichen und in Grossbuchstaben
 * normalisiert und im Modell verschluesselt gespeichert. Sie erscheinen nur
 * dann in der Mieterabrechnung, wenn der Vermieter dies ausdruecklich waehlt.
 * Eine Steuernummer wird bewusst nicht erfasst.
 */
class LandlordRequest extends GermanFormRequest
{
    public function authorize(): bool
    {
        // Die Autorisierung erfolgt im Controller ueber PropertyPolicy und
        // LandlordPolicy mit Object-Level-Check.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'iban' => $this->normalizeBankValue($this->input('iban')),
            'bic' => $this->normalizeBankValue($this->input('bic')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sender_name' => ['required', 'string', 'max:190'],
            'company_name' => ['nullable', 'string', 'max:190'],
            'address_line' => ['required', 'string', 'max:190'],
            'address_extra' => ['nullable', 'string', 'max:190'],
            'postal_code' => ['required', 'string', 'max:16'],
            'city' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:64'],
            'iban' => ['nullable', 'string', 'regex:/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/'],
            'bic' => ['nullable', 'string', 'regex:/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/'],
            'account_holder' => ['nullable', 'string', 'max:190'],
            'show_bank_details_on_statement' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'sender_name' => 'Name des Vermieters',
            'company_name' => 'Firma',
            'address_line' => 'Straße und Hausnummer',
            'address_extra' => 'Adresszusatz',
            'postal_code' => 'Postleitzahl',
            'city' => 'Ort',
            'email' => 'E-Mail-Adresse',
            'phone' => 'Telefon',
            'iban' => 'IBAN',
            'bic' => 'BIC',
            'account_holder' => 'Kontoinhaber',
            'show_bank_details_on_statement' => 'Bankverbindung in der Abrechnung anzeigen',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function eigeneMeldungen(): array
    {
        return [
            'iban.regex' => 'Bitte prüfen Sie die IBAN. Sie beginnt mit dem Länderkennzeichen und zwei Prüfziffern.',
            'bic.regex' => 'Bitte prüfen Sie die BIC. Sie besteht aus 8 oder 11 Zeichen.',
        ];
    }

    private function normalizeBankValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtoupper(preg_replace('/\s+/', '', $value) ?? '');

        return $normalized === '' ? null : $normalized;
    }
}
