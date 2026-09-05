<?php

declare(strict_types=1);

namespace App\Http\Requests\Heating;

use App\Application\Heating\EuroAmountInput;
use App\Application\Heating\StoreManualHeatingEntries;
use App\Http\Requests\GermanFormRequest;
use Illuminate\Contracts\Validation\Validator;

/**
 * Manuelle Erfassung der Heizkosten je Einheit (Fall B).
 *
 * Betraege werden in Euro erfasst, zum Beispiel 1.234,56. Die Umrechnung auf
 * Cent erfolgt serverseitig ausschliesslich ueber BigDecimal
 * (App\Application\Heating\EuroAmountInput), niemals ueber einen
 * float-Zwischenschritt.
 */
class StoreManualHeatingRequest extends GermanFormRequest
{
    /**
     * @var list<string>
     */
    public const array AMOUNT_FIELDS = ['heizung', 'warmwasser', 'co2_vermieter', 'co2_mieter', 'sonstige'];

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
            'einheiten' => ['nullable', 'array'],
            'einheiten.*' => ['array'],
            'einheiten.*.heizung' => ['nullable', 'string', 'max:20'],
            'einheiten.*.warmwasser' => ['nullable', 'string', 'max:20'],
            'einheiten.*.co2_vermieter' => ['nullable', 'string', 'max:20'],
            'einheiten.*.co2_mieter' => ['nullable', 'string', 'max:20'],
            'einheiten.*.sonstige' => ['nullable', 'string', 'max:20'],
            'gesamtbetrag' => ['nullable', 'string', 'max:20'],
            'herkunft' => ['nullable', 'string', 'max:2000'],
            'quelle' => ['nullable', 'string', 'in:MANUELL,EXTERN'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'gesamtbetrag' => 'Gesamtbetrag der Heizkosten',
            'herkunft' => 'Herkunft der Berechnung',
            'quelle' => 'maßgebliche Quelle',
        ];
    }

    /**
     * Jeder erfasste Betrag muss exakt auswertbar sein. Es wird nie gerundet
     * und nie geschaetzt.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $einheiten = $this->input('einheiten');

            if (is_array($einheiten)) {
                foreach ($einheiten as $unitId => $werte) {
                    if (! is_array($werte)) {
                        continue;
                    }

                    foreach (self::AMOUNT_FIELDS as $feld) {
                        $wert = $werte[$feld] ?? null;

                        if ($wert !== null && ! is_string($wert)) {
                            $validator->errors()->add(
                                sprintf('einheiten.%s.%s', $unitId, $feld),
                                'Bitte geben Sie den Betrag in der Form 1.234,56 an.'
                            );

                            continue;
                        }

                        if (! EuroAmountInput::isValid($wert)) {
                            $validator->errors()->add(
                                sprintf('einheiten.%s.%s', $unitId, $feld),
                                'Bitte geben Sie den Betrag in der Form 1.234,56 an. Mehr als zwei Nachkommastellen '
                                .'sind nicht zulässig.'
                            );
                        }
                    }
                }
            }

            $gesamtbetrag = $this->input('gesamtbetrag');

            if ($gesamtbetrag !== null && (! is_string($gesamtbetrag) || ! EuroAmountInput::isValid($gesamtbetrag))) {
                $validator->errors()->add(
                    'gesamtbetrag',
                    'Bitte geben Sie den Gesamtbetrag in der Form 1.234,56 an oder lassen Sie das Feld leer.'
                );
            }
        });
    }

    /**
     * Betraege je Einheit in der Form Einheit => Feld => Betrag.
     *
     * @return array<string, array<string, string|null>>
     */
    public function amountsByUnit(): array
    {
        $einheiten = $this->input('einheiten');
        $ergebnis = [];

        if (! is_array($einheiten)) {
            return [];
        }

        foreach ($einheiten as $unitId => $werte) {
            if (! is_array($werte)) {
                continue;
            }

            $zeile = [];

            foreach (self::AMOUNT_FIELDS as $feld) {
                $wert = $werte[$feld] ?? null;
                $zeile[$feld] = is_string($wert) ? $wert : null;
            }

            $ergebnis[(string) $unitId] = $zeile;
        }

        return $ergebnis;
    }

    public function declaredTotal(): ?string
    {
        $wert = $this->input('gesamtbetrag');

        return is_string($wert) && trim($wert) !== '' ? $wert : null;
    }

    public function calculationOrigin(): ?string
    {
        $wert = $this->input('herkunft');

        return is_string($wert) && trim($wert) !== '' ? trim($wert) : null;
    }

    public function sourceDecision(): ?string
    {
        $wert = $this->input('quelle');

        return match ($wert) {
            StoreManualHeatingEntries::DECISION_MANUAL => StoreManualHeatingEntries::DECISION_MANUAL,
            StoreManualHeatingEntries::DECISION_EXTERNAL => StoreManualHeatingEntries::DECISION_EXTERNAL,
            default => null,
        };
    }
}
