<?php

declare(strict_types=1);

namespace App\Http\Requests\Portal;

use App\Enums\BillingMode;
use App\Http\Requests\GermanFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Anlage eines Abrechnungslaufs.
 *
 * Vorgabe des Masterprompts, Schritt 1: Abrechnungszeitraum mit Standard
 * 01.01. bis 31.12. des Vorjahres, unterjaehrig zulaessig.
 *
 * Der Zeitraum darf zwoelf Monate nicht ueberschreiten. Der Grenzwert steht in
 * config/smartabrechnen.php unter tolerances.billing_period_months_limit.
 */
class BillingRunRequest extends GermanFormRequest
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
            'property_id' => ['required', 'string', 'max:26'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'mode' => ['required', Rule::enum(BillingMode::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $pruefer): void {
            $start = $this->input('period_start');
            $ende = $this->input('period_end');

            if (! is_string($start) || ! is_string($ende) || $start === '' || $ende === '') {
                return;
            }

            $grenze = (int) config('smartabrechnen.tolerances.billing_period_months_limit', 12);

            $von = strtotime($start);
            $bis = strtotime($ende);

            if ($von === false || $bis === false) {
                return;
            }

            $tage = (int) floor(($bis - $von) / 86400) + 1;

            if ($tage > 366) {
                $pruefer->errors()->add(
                    'period_end',
                    sprintf(
                        'Ein Abrechnungszeitraum darf höchstens %d Monate umfassen. '
                        .'Bitte legen Sie für einen längeren Zeitraum mehrere Abrechnungen an.',
                        $grenze
                    )
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'property_id' => 'Objekt',
            'period_start' => 'Beginn des Abrechnungszeitraums',
            'period_end' => 'Ende des Abrechnungszeitraums',
            'mode' => 'Abrechnungsweg',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function eigeneMeldungen(): array
    {
        return [
            'period_end.after_or_equal' => 'Das Ende des Abrechnungszeitraums darf nicht vor dem Beginn liegen.',
            'property_id.required' => 'Bitte wählen Sie das Objekt aus, für das abgerechnet werden soll.',
        ];
    }
}
