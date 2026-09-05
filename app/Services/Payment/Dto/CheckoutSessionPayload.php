<?php

declare(strict_types=1);

namespace App\Services\Payment\Dto;

/**
 * Vollstaendige Angaben einer gehosteten Zahlungsseite (Abschnitt 15.1).
 *
 * DATENSPARSAMKEIT, verbindlich: Diese Klasse ist die einzige Datenquelle des
 * Zahlungsanbieters. Sie besitzt bewusst KEIN Feld fuer Mieternamen,
 * Mietvertragsdaten, Belegangaben, Objektanschriften oder Dateiinhalte. Die
 * Leistungsbezeichnung ist neutral und nennt ausschliesslich die Leistung, das
 * Abrechnungsjahr und die Anzahl der Abrechnungen. Damit koennen an den
 * Anbieter keine Mieter- oder Belegdaten gelangen, auch nicht durch einen
 * spaeteren Programmierfehler an der Aufrufstelle.
 *
 * Beträge sind Integer in Cent und stammen ausschliesslich aus der Datenbank
 * beziehungsweise aus der serverseitigen Preisberechnung.
 */
final readonly class CheckoutSessionPayload
{
    /**
     * @param  string  $productName  neutrale Leistungsbezeichnung, keine Mieterdaten
     * @param  int  $quantity  Anzahl der erzeugten Mieterabrechnungen
     * @param  int  $unitAmountGrossCent  Bruttopreis je Abrechnung in Cent
     * @param  int  $baseAmountGrossCent  optionaler Grundpreis je Lauf in Cent
     * @param  string  $clientReferenceId  Kennung des Abrechnungslaufs
     * @param  array<string, string>  $metadata  ausschliesslich technische Kennungen
     */
    public function __construct(
        public string $productName,
        public int $quantity,
        public int $unitAmountGrossCent,
        public int $baseAmountGrossCent,
        public string $currency,
        public string $clientReferenceId,
        public array $metadata,
        public string $successUrl,
        public string $cancelUrl,
        public string $idempotencyKey,
        public ?string $customerEmail = null,
        public ?string $baseProductName = null,
    ) {}

    public function totalGrossCent(): int
    {
        return $this->quantity * $this->unitAmountGrossCent + $this->baseAmountGrossCent;
    }

    public function hasBaseAmount(): bool
    {
        return $this->baseAmountGrossCent > 0;
    }
}
