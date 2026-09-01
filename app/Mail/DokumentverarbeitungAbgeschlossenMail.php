<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Die automatische Auswertung der hochgeladenen Unterlagen ist beendet.
 *
 * Die Nachricht nennt bewusst keine Betraege und keine Belegdaten. Sie fuehrt
 * den Nutzer in das Portal, wo die ausgelesenen Inhaltsdaten stehen. Die
 * Originaldateien sind zu diesem Zeitpunkt bereits geloescht.
 */
final class DokumentverarbeitungAbgeschlossenMail extends TransactionalMail
{
    public function __construct(
        private readonly string $anrede,
        private readonly string $objekt,
        private readonly int $jahr,
        private readonly int $dokumente,
        private readonly string $portalUrl,
    ) {}

    public function template(): string
    {
        return 'dokumentverarbeitung-abgeschlossen';
    }

    public function betreff(): string
    {
        return sprintf('Ihre Unterlagen für %s sind ausgewertet', $this->objekt);
    }

    public function blade(): string
    {
        return 'emails.transaktion.dokumentverarbeitung-abgeschlossen';
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
            'dokumente' => $this->dokumente,
            'portalUrl' => $this->portalUrl,
        ];
    }
}
