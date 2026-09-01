<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\GeneratedDocument;
use Illuminate\Support\Carbon;

/**
 * Bestaetigung einer erfolgreichen Zahlung.
 *
 * Kritische Zahlungsnachricht. Sie wird auch an eine unterdrueckte Adresse
 * versendet, weil der Nutzer den Zahlungsstand kennen muss.
 *
 * Die Leistungsrechnung der Hausverwaltung Mueller GmbH darf optional
 * angehaengt werden (Masterprompt 16). Eine Mieterabrechnung niemals.
 */
final class ZahlungBestaetigtMail extends TransactionalMail
{
    public function __construct(
        private readonly string $anrede,
        private readonly string $objekt,
        private readonly int $jahr,
        private readonly int $abrechnungen,
        private readonly int $betragCent,
        private readonly Carbon $bezahltAm,
        private readonly string $portalUrl,
        private readonly ?GeneratedDocument $rechnung = null,
    ) {}

    public function template(): string
    {
        return 'zahlung-bestaetigt';
    }

    public function betreff(): string
    {
        return 'Ihre Zahlung ist eingegangen';
    }

    public function blade(): string
    {
        return 'emails.transaktion.zahlung-bestaetigt';
    }

    public function istKritisch(): bool
    {
        return true;
    }

    /**
     * @return list<GeneratedDocument>
     */
    public function anhangDokumente(): array
    {
        return $this->rechnung === null ? [] : [$this->rechnung];
    }

    /**
     * @return array<string, mixed>
     */
    public function daten(): array
    {
        return [
            'anrede' => $this->anrede,
            'objekt' => $this->objekt,
            'jahr' => $this->jahr,
            'abrechnungen' => $this->abrechnungen,
            'betrag' => Format::betrag($this->betragCent),
            'bezahltAm' => Format::datum($this->bezahltAm),
            'portalUrl' => $this->portalUrl,
            'rechnungAngehaengt' => $this->rechnung !== null,
        ];
    }
}
