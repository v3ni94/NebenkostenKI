<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\GeneratedDocument;

/**
 * Die Leistungsrechnung der Hausverwaltung Mueller GmbH steht bereit.
 *
 * Die Rechnung darf angehaengt werden. Sie enthaelt keine Mieterdaten, sondern
 * ausschliesslich die Leistung gegenueber dem Nutzer.
 */
final class HvmRechnungVerfuegbarMail extends TransactionalMail
{
    public function __construct(
        private readonly string $anrede,
        private readonly string $rechnungsnummer,
        private readonly int $bruttoCent,
        private readonly string $ausgestelltAm,
        private readonly string $portalUrl,
        private readonly ?GeneratedDocument $rechnung = null,
    ) {}

    public function template(): string
    {
        return 'hvm-rechnung-verfuegbar';
    }

    public function betreff(): string
    {
        return sprintf('Ihre Rechnung %s', $this->rechnungsnummer);
    }

    public function blade(): string
    {
        return 'emails.transaktion.hvm-rechnung-verfuegbar';
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
            'rechnungsnummer' => $this->rechnungsnummer,
            'brutto' => Format::betrag($this->bruttoCent),
            'ausgestelltAm' => $this->ausgestelltAm,
            'portalUrl' => $this->portalUrl,
            'rechnungAngehaengt' => $this->rechnung !== null,
        ];
    }
}
