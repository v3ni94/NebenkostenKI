<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Support\Carbon;

/**
 * Erinnerung einige Tage vor der endgueltigen Kontoloeschung.
 *
 * Kritische Kontonachricht, siehe LoeschantragEingegangenMail. Sie wird je
 * Antrag genau einmal versendet und nennt den letzten Tag der Ruecknahme.
 */
final class LoeschantragErinnerungMail extends TransactionalMail
{
    public function __construct(
        private readonly string $anrede,
        private readonly Carbon $faelligAm,
        private readonly int $verbleibendeTage,
        private readonly string $datenschutzUrl,
    ) {}

    public function template(): string
    {
        return 'loeschantrag-erinnerung';
    }

    public function betreff(): string
    {
        return 'Erinnerung: Ihr Konto wird in Kürze gelöscht';
    }

    public function blade(): string
    {
        return 'emails.transaktion.loeschantrag-erinnerung';
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
            'verbleibendeTage' => $this->verbleibendeTage,
            'datenschutzUrl' => $this->datenschutzUrl,
        ];
    }
}
