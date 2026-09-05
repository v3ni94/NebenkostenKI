<?php

declare(strict_types=1);

namespace App\Services\Pdf\Support;

/**
 * Pflichtangaben des Betreibers für die HVM-Rechnung (Abschnitt 2.1, 15.2).
 *
 * Die Angaben werden ausschließlich aus config('smartabrechnen.operator')
 * gelesen. Fehlende Steuer- und Bankdaten werden als sichtbarer Platzhalter
 * gerendert und niemals erfunden. missingMandatoryFields() benennt genau die
 * Felder, die den Livegang blockieren.
 */
final readonly class OperatorDetails
{
    /**
     * @param  array<string, string|null>  $values
     */
    private function __construct(
        private array $values,
        public string $placeholderText,
    ) {}

    public static function fromConfig(): self
    {
        $raw = config('smartabrechnen.operator');
        $raw = is_array($raw) ? $raw : [];

        $values = [];

        foreach ($raw as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $values[$key] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        $placeholder = $values['placeholder_text'] ?? null;

        return new self($values, $placeholder ?? '[vor Livegang ergänzen]');
    }

    /**
     * Feldwert oder sichtbarer Platzhalter.
     */
    public function value(string $key): string
    {
        return $this->values[$key] ?? $this->placeholderText;
    }

    public function has(string $key): bool
    {
        return ($this->values[$key] ?? null) !== null;
    }

    public function legalName(): string
    {
        return $this->value('legal_name');
    }

    public function addressLine(): string
    {
        return $this->value('address_line');
    }

    public function cityLine(): string
    {
        return trim($this->value('postal_code').' '.$this->value('city'));
    }

    public function registerLine(): string
    {
        return $this->value('register_court').', '.$this->value('register_number');
    }

    public function managingDirectorLine(): string
    {
        return 'Geschäftsführer: '.$this->value('managing_director');
    }

    public function website(): string
    {
        return $this->value('website');
    }

    public function taxId(): string
    {
        return $this->value('tax_id');
    }

    public function vatId(): string
    {
        return $this->value('vat_id');
    }

    public function iban(): string
    {
        return $this->value('iban');
    }

    public function bic(): string
    {
        return $this->value('bic');
    }

    public function masterdataConfirmed(): bool
    {
        $confirmed = config('smartabrechnen.operator.masterdata_confirmed');

        return $confirmed === true;
    }

    /**
     * Vor Livegang zwingend zu bestätigende Pflichtfelder, die noch fehlen.
     *
     * @return list<string>
     */
    public function missingMandatoryFields(): array
    {
        $labels = [
            'legal_name' => 'Firmenname',
            'address_line' => 'Anschrift',
            'postal_code' => 'Postleitzahl',
            'city' => 'Ort',
            'register_court' => 'Registergericht',
            'register_number' => 'Handelsregisternummer',
            'managing_director' => 'Geschäftsführer',
            'tax_id' => 'Steuernummer',
            'vat_id' => 'Umsatzsteuer-Identifikationsnummer',
            'iban' => 'IBAN',
            'bic' => 'BIC',
        ];

        $missing = [];

        foreach ($labels as $key => $label) {
            if (! $this->has($key)) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    /**
     * True, solange Pflichtangaben fehlen oder die Stammdaten nicht
     * ausdrücklich bestätigt sind. Die produktive Rechnungserzeugung ist dann
     * ein Livegang-Blocker.
     */
    public function isLaunchBlocked(): bool
    {
        return $this->missingMandatoryFields() !== [] || ! $this->masterdataConfirmed();
    }
}
