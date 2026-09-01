<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Die Vorschau mit Wasserzeichen steht bereit.
 *
 * Die Vorschau wird ausdruecklich nicht angehaengt. Jede Vorschauseite traegt
 * ein Wasserzeichen und ist nicht zur Verwendung gegenueber Mietern bestimmt
 * (Masterprompt Schritt 10).
 */
final class VorschauBereitMail extends TransactionalMail
{
    public function __construct(
        private readonly string $anrede,
        private readonly string $objekt,
        private readonly int $jahr,
        private readonly int $abrechnungen,
        private readonly int $preisGesamtCent,
        private readonly string $portalUrl,
    ) {}

    public function template(): string
    {
        return 'vorschau-bereit';
    }

    public function betreff(): string
    {
        return sprintf('Ihre Vorschau für %s ist bereit', $this->objekt);
    }

    public function blade(): string
    {
        return 'emails.transaktion.vorschau-bereit';
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
            'preis' => Format::betrag($this->preisGesamtCent),
            'portalUrl' => $this->portalUrl,
        ];
    }
}
