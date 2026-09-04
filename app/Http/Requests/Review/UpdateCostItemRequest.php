<?php

declare(strict_types=1);

namespace App\Http\Requests\Review;

use App\Application\Heating\EuroAmountInput;
use App\Http\Requests\GermanFormRequest;
use Illuminate\Contracts\Validation\Validator;

/**
 * Bearbeitung einer Kostenposition in der Pruefung.
 *
 * Der Betrag wird in Euro erfasst und serverseitig ueber
 * App\Application\Heating\EuroAmountInput in Cent umgerechnet. Ein nicht
 * auswertbarer Betrag ist ein Validierungsfehler und wird weder gerundet noch
 * still verworfen. Eine bewusste Aufnahme einer nicht umlagefaehigen Position
 * erfordert eine Begruendung.
 */
class UpdateCostItemRequest extends GermanFormRequest
{
    /**
     * @var list<string>
     */
    public const array AMOUNT_FIELDS = ['betrag_euro', 'lohnanteil_euro'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Jeder erfasste Betrag muss exakt auswertbar sein. Es wird nie gerundet
     * und nie geschaetzt.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (self::AMOUNT_FIELDS as $feld) {
                $wert = $this->input($feld);

                if ($wert === null || $validator->errors()->has($feld)) {
                    continue;
                }

                if (! is_string($wert) || ! EuroAmountInput::isValid($wert)) {
                    $validator->errors()->add(
                        $feld,
                        sprintf(
                            'Bitte geben Sie %s in der Form 1.234,56 an. Mehr als zwei Nachkommastellen '
                            .'sind nicht zulässig. Ein Betrag wird nicht geschätzt.',
                            $this->attributes()[$feld] ?? $feld
                        )
                    );
                }
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:190'],
            'supplier_name' => ['nullable', 'string', 'max:190'],
            'betrag_euro' => ['required', 'string', 'max:20'],
            'cost_category_id' => ['nullable', 'string', 'max:26'],
            'document_date' => ['nullable', 'date'],
            'service_period_start' => ['nullable', 'date'],
            'service_period_end' => ['nullable', 'date', 'after_or_equal:service_period_start'],
            'lohnanteil_euro' => ['nullable', 'string', 'max:20'],
            'direct_unit_id' => ['nullable', 'string', 'max:26'],
            'include_despite_status' => ['nullable', 'boolean'],
            'apportionment_override_reason' => ['nullable', 'string', 'max:1000', 'required_if:include_despite_status,1'],
        ];
    }

    /**
     * Umrechnung in die Datenform der Anwendung. Betraege ausschliesslich in
     * Cent.
     *
     * @return array<string, mixed>
     */
    public function daten(): array
    {
        return [
            'description' => $this->string('description')->toString(),
            'supplier_name' => $this->input('supplier_name'),
            'amount_cent' => $this->cent('betrag_euro'),
            'cost_category_id' => $this->input('cost_category_id'),
            'document_date' => $this->input('document_date'),
            'service_period_start' => $this->input('service_period_start'),
            'service_period_end' => $this->input('service_period_end'),
            'labor_share_cent' => $this->cent('lohnanteil_euro'),
            'direct_unit_id' => $this->input('direct_unit_id'),
            'include_despite_status' => $this->boolean('include_despite_status'),
            'apportionment_override_reason' => $this->input('apportionment_override_reason'),
        ];
    }

    /**
     * Eurobetrag als Integer in Cent.
     *
     * Die Umrechnung laeuft ausschliesslich ueber BigDecimal (EuroAmountInput).
     * Ein Zwischenschritt ueber float ist nach Grundsatz 8 unzulaessig, weil
     * ein binaerer Gleitkommawert einen Betrag wie 8.235,70 EUR nicht exakt
     * darstellt und damit einen Rundungsfehler in die Abrechnung tragen
     * koennte. Die Gueltigkeit ist durch withValidator() sichergestellt.
     */
    protected function cent(string $feld): ?int
    {
        $wert = $this->input($feld);

        if (! is_string($wert)) {
            return null;
        }

        return EuroAmountInput::parse($wert)?->cents;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'description' => 'Bezeichnung',
            'supplier_name' => 'Lieferant',
            'betrag_euro' => 'Betrag',
            'cost_category_id' => 'Kostenart',
            'document_date' => 'Belegdatum',
            'service_period_start' => 'Beginn des Leistungszeitraums',
            'service_period_end' => 'Ende des Leistungszeitraums',
            'lohnanteil_euro' => 'Lohnanteil nach § 35a EStG',
            'direct_unit_id' => 'Einheit',
            'apportionment_override_reason' => 'Begründung',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function eigeneMeldungen(): array
    {
        return [
            'betrag_euro.required' => 'Bitte geben Sie den Betrag an. Ein Betrag wird nicht geschätzt.',
            'apportionment_override_reason.required_if' => 'Bitte begründen Sie, warum diese Position dennoch '
                .'umgelegt werden soll. Die Begründung wird gespeichert und ist keine juristische Freigabe.',
        ];
    }
}
