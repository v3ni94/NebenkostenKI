<?php

declare(strict_types=1);

namespace App\Http\Requests\Wizard;

use App\Application\Heating\EuroAmountInput;
use App\Enums\ValueSource;
use App\Http\Requests\GermanFormRequest;
use Illuminate\Contracts\Validation\Validator;

/**
 * Schritt 7: erfasste Vorauszahlungen je Mietverhältnis.
 *
 * Die Beträge werden in deutscher Schreibweise erfasst und über
 * App\Application\Heating\EuroAmountInput mit brick/math in Cent umgerechnet.
 * Es entsteht kein float (ADR-004). Eine leere Eingabe ist kein Betrag von
 * 0,00 EUR, sondern eine fehlende Angabe. Ein nicht auswertbarer Betrag ist
 * ein Validierungsfehler, er wird weder gerundet noch still verworfen.
 */
class StorePrepaymentsRequest extends GermanFormRequest
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
            'zeilen' => ['required', 'array'],
            'zeilen.*.ist' => ['nullable', 'string', 'max:20'],
            'zeilen.*.annahme' => ['nullable', 'boolean'],
            'zeilen.*.herkunft' => ['nullable', 'string', 'in:'.implode(',', $this->sources())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'zeilen' => 'Vorauszahlungen',
            'zeilen.*.ist' => 'tatsächlich geleistete Vorauszahlungen',
            'zeilen.*.annahme' => 'Bestätigung der Annahme Ist gleich Soll',
            'zeilen.*.herkunft' => 'Herkunft',
        ];
    }

    /**
     * Jeder erfasste Ist-Betrag muss exakt auswertbar sein.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $zeilen = $this->input('zeilen');

            if (! is_array($zeilen)) {
                return;
            }

            foreach ($zeilen as $tenancyId => $zeile) {
                if (! is_array($zeile)) {
                    continue;
                }

                $wert = $zeile['ist'] ?? null;

                if ($wert === null || is_int($wert)) {
                    continue;
                }

                if (! is_string($wert) || ! EuroAmountInput::isValid($wert)) {
                    $validator->errors()->add(
                        sprintf('zeilen.%s.ist', $tenancyId),
                        'Bitte geben Sie die tatsächlich geleisteten Vorauszahlungen in der Form 1.234,56 an. '
                        .'Mehr als zwei Nachkommastellen sind nicht zulässig.'
                    );
                }
            }
        });
    }

    /**
     * Aufbereitete Eingaben für App\Application\Wizard\PrepaymentWorkspace.
     *
     * @return array<string, array{ist_cent: int|null, annahme: bool, herkunft: string}>
     */
    public function eingaben(): array
    {
        $zeilen = $this->input('zeilen');
        $ergebnis = [];

        if (! is_array($zeilen)) {
            return $ergebnis;
        }

        foreach ($zeilen as $tenancyId => $zeile) {
            if (! is_array($zeile)) {
                continue;
            }

            $herkunft = $zeile['herkunft'] ?? null;

            $ergebnis[(string) $tenancyId] = [
                'ist_cent' => $this->cent($zeile['ist'] ?? null),
                'annahme' => (bool) ($zeile['annahme'] ?? false),
                'herkunft' => is_string($herkunft) && $herkunft !== '' ? $herkunft : ValueSource::MANUELL->value,
            ];
        }

        return $ergebnis;
    }

    /**
     * Betrag als Integer in Cent. Die Gültigkeit ist durch withValidator()
     * bereits sichergestellt.
     */
    private function cent(mixed $wert): ?int
    {
        if (! is_string($wert)) {
            return is_int($wert) ? $wert * 100 : null;
        }

        return EuroAmountInput::parse($wert)?->cents;
    }

    /**
     * @return list<string>
     */
    private function sources(): array
    {
        return array_map(static fn (ValueSource $source): string => $source->value, ValueSource::cases());
    }
}
