<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\ReminderWindow;

/**
 * Automatische Erinnerung an die Abrechnung des Vorjahres.
 *
 * Keine kritische Nachricht. Eine unterdrueckte Adresse erhaelt sie nicht.
 *
 * Jede Erinnerung enthaelt zwei Links:
 *   1. einen signierten CTA, der den vorausgefuellten Folgejahreslauf oeffnet
 *   2. einen signierten Abmeldelink, der ohne Anmeldung funktioniert
 *
 * Beide Adressen enthalten keine Kundendaten und keine erratbare Kennung.
 */
final class ErinnerungFolgejahrMail extends TransactionalMail
{
    public function __construct(
        private readonly string $anrede,
        private readonly string $objekt,
        private readonly int $jahr,
        private readonly ReminderWindow $fenster,
        private readonly string $startUrl,
        private readonly string $abmeldeUrl,
    ) {}

    public function template(): string
    {
        return 'erinnerung-folgejahr';
    }

    public function betreff(): string
    {
        return $this->fenster === ReminderWindow::DEZEMBER
            ? sprintf('Frist zum Jahresende: Abrechnung %d für %s', $this->jahr, $this->objekt)
            : sprintf('Erinnerung: Abrechnung %d für %s', $this->jahr, $this->objekt);
    }

    public function blade(): string
    {
        return 'emails.transaktion.erinnerung-folgejahr';
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
            'fenster' => $this->fenster,
            'istDezember' => $this->fenster === ReminderWindow::DEZEMBER,
            'startUrl' => $this->startUrl,
            'abmeldeUrl' => $this->abmeldeUrl,
        ];
    }
}
