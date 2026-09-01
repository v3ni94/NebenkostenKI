<?php

declare(strict_types=1);

namespace App\Services\Pdf\View;

/**
 * Anschrift für Absenderzeile und Anschriftfeld nach DIN 5008.
 *
 * Es werden ausschließlich übergebene Angaben gedruckt. Fehlende Zeilen
 * entfallen, es wird nichts ergänzt.
 */
final readonly class PostalAddress
{
    public function __construct(
        public string $name,
        public ?string $addressExtra = null,
        public ?string $street = null,
        public ?string $postalCode = null,
        public ?string $city = null,
        public ?string $country = null,
    ) {}

    /**
     * Zeilen des Anschriftfeldes ohne Leerzeilen.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $cityLine = trim(($this->postalCode ?? '').' '.($this->city ?? ''));

        $candidates = [
            $this->name,
            $this->addressExtra,
            $this->street,
            $cityLine,
            $this->country,
        ];

        $lines = [];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $lines[] = trim($candidate);
            }
        }

        return $lines;
    }

    /**
     * Einzeilige Absenderzeile nach DIN 5008, Bestandteile mit Komma getrennt.
     */
    public function senderLine(): string
    {
        return implode(', ', $this->lines());
    }
}
