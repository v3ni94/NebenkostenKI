<?php

declare(strict_types=1);

namespace App\Http\Requests\Wizard;

use App\Enums\ValueSource;
use App\Http\Requests\GermanFormRequest;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * Schritt 7: erfasste Vorauszahlungen je Mietverhältnis.
 *
 * Die Beträge werden in deutscher Schreibweise erfasst und mit brick/math in
 * Cent umgerechnet. Es entsteht kein float (ADR-004). Eine leere Eingabe ist
 * kein Betrag von 0,00 EUR, sondern eine fehlende Angabe.
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
     * Deutscher Betrag als Integer in Cent.
     */
    private function cent(mixed $wert): ?int
    {
        if (! is_string($wert)) {
            return is_int($wert) ? $wert * 100 : null;
        }

        $bereinigt = str_replace([' ', '.'], '', trim($wert));
        $bereinigt = str_replace(',', '.', $bereinigt);

        if ($bereinigt === '') {
            return null;
        }

        try {
            return BigDecimal::of($bereinigt)
                ->withPointMovedRight(2)
                ->toScale(0, RoundingMode::HALF_UP)
                ->toInt();
        } catch (MathException) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function sources(): array
    {
        return array_map(static fn (ValueSource $source): string => $source->value, ValueSource::cases());
    }
}
