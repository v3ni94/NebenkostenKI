<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Die wasserzeichenfreien Finalabrechnungen stehen bereit.
 *
 * VERBINDLICH (Masterprompt 16): Finale Mieterabrechnungen werden NICHT
 * unverschluesselt als Anhang versendet. Die Nachricht enthaelt ausschliesslich
 * einen zeitlich begrenzten, kontogebundenen Downloadlink. Der Link ist
 * signiert, laeuft nach der konfigurierten Frist ab und ersetzt die Anmeldung
 * nicht.
 *
 * anhangDokumente() bleibt deshalb bewusst leer.
 */
final class FinalabrechnungenVerfuegbarMail extends TransactionalMail
{
    public function __construct(
        private readonly string $anrede,
        private readonly string $objekt,
        private readonly int $jahr,
        private readonly int $abrechnungen,
        private readonly string $downloadUrl,
        private readonly int $gueltigkeitMinuten,
        private readonly string $portalUrl,
    ) {}

    public function template(): string
    {
        return 'finalabrechnungen-verfuegbar';
    }

    public function betreff(): string
    {
        return sprintf('Ihre Abrechnungen für %s stehen bereit', $this->objekt);
    }

    public function blade(): string
    {
        return 'emails.transaktion.finalabrechnungen-verfuegbar';
    }

    public function istKritisch(): bool
    {
        return true;
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
            'downloadUrl' => $this->downloadUrl,
            'gueltigkeitMinuten' => $this->gueltigkeitMinuten,
            'portalUrl' => $this->portalUrl,
        ];
    }
}
