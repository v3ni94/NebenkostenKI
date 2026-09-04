<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Hinweis an den Kontoinhaber, dass der Betreiber den Zweitfaktor
 * zurueckgesetzt hat.
 *
 * Kritische Kontonachricht: Der Inhaber muss erfahren, dass sein Konto bis
 * zur erneuten Einrichtung ohne zweiten Faktor ist. Die Nachricht enthaelt
 * keine Begruendung des Betreibers und keine Kontodaten.
 */
final class ZweitfaktorZurueckgesetztMail extends TransactionalMail
{
    public function __construct(
        private readonly string $anrede,
        private readonly string $einrichtungUrl,
    ) {}

    public function template(): string
    {
        return 'zweitfaktor-zurueckgesetzt';
    }

    public function betreff(): string
    {
        return 'Ihr zweiter Faktor wurde zurückgesetzt';
    }

    public function blade(): string
    {
        return 'emails.transaktion.zweitfaktor-zurueckgesetzt';
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
            'einrichtungUrl' => $this->einrichtungUrl,
        ];
    }
}
