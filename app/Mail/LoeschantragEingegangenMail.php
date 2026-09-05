<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Support\Carbon;

/**
 * Bestaetigung eines Loeschantrags an den Kontoinhaber.
 *
 * Kritische Kontonachricht: Sie wird auch an eine unterdrueckte Adresse
 * versendet, weil der Inhaber erfahren muss, dass sein Konto zur Loeschung
 * vorgemerkt ist. Nur so kann er einen Antrag zuruecknehmen, den ein Dritter
 * ueber eine uebernommene Sitzung gestellt hat.
 *
 * Die Nachricht nennt Faelligkeit und Ruecknahmeweg. Sie enthaelt keine
 * Kontodaten ausser der Anrede.
 */
final class LoeschantragEingegangenMail extends TransactionalMail
{
    public function __construct(
        private readonly string $anrede,
        private readonly Carbon $faelligAm,
        private readonly int $fristTage,
        private readonly string $datenschutzUrl,
    ) {}

    public function template(): string
    {
        return 'loeschantrag-eingegangen';
    }

    public function betreff(): string
    {
        return 'Ihr Löschantrag ist eingegangen';
    }

    public function blade(): string
    {
        return 'emails.transaktion.loeschantrag-eingegangen';
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
            'faelligAm' => Format::datum($this->faelligAm->copy()->timezone('Europe/Berlin')),
            'fristTage' => $this->fristTage,
            'datenschutzUrl' => $this->datenschutzUrl,
        ];
    }
}
