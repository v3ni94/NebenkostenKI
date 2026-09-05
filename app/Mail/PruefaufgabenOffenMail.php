<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Es sind konkrete Pruefaufgaben offen.
 *
 * Fehlende Werte werden niemals geschaetzt. Sie bleiben offen und erzeugen eine
 * Pruefaufgabe (Masterprompt 0 Nummer 5). Die Nachricht benennt die Anzahl und
 * die Art der offenen Punkte, nicht deren Inhalt.
 */
final class PruefaufgabenOffenMail extends TransactionalMail
{
    /**
     * @param  list<string>  $themen  Kurze Sachbezeichnungen der offenen Punkte
     */
    public function __construct(
        private readonly string $anrede,
        private readonly string $objekt,
        private readonly int $jahr,
        private readonly int $offen,
        private readonly array $themen,
        private readonly string $portalUrl,
    ) {}

    public function template(): string
    {
        return 'pruefaufgaben-offen';
    }

    public function betreff(): string
    {
        return sprintf('Bitte prüfen Sie %d offene Punkte zu %s', $this->offen, $this->objekt);
    }

    public function blade(): string
    {
        return 'emails.transaktion.pruefaufgaben-offen';
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
            'offen' => $this->offen,
            'themen' => $this->themen,
            'portalUrl' => $this->portalUrl,
        ];
    }
}
