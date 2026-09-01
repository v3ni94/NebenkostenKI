<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Hinweis auf ein bereits bestehendes Konto.
 *
 * WARUM DIESE NACHRICHT
 *
 * Die Registrierung darf nicht verraten, ob zu einer Adresse ein Konto besteht.
 * Sie antwortet deshalb bei einer bereits registrierten Adresse mit derselben
 * Bestaetigung wie bei einer neuen Adresse und legt kein zweites Konto an. Damit
 * der berechtigte Inhaber den Vorgang versteht, geht stattdessen diese sachliche
 * Nachricht an die bestehende Adresse.
 *
 * INHALT
 *
 * Die Nachricht nennt keine Kontodaten, kein Passwort und keinen Namen aus dem
 * Registrierungsversuch. Sie enthaelt ausschliesslich die Wege, die dem Inhaber
 * weiterhelfen: Anmeldung und Zuruecksetzen des Passworts.
 *
 * Der Absender kommt ausschliesslich aus der Mailkonfiguration
 * (config/mail.php, MAIL_FROM_*), es wird keine Adresse im Code gesetzt.
 */
class KontoBereitsVorhanden extends Notification
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $daten = [
            'anrede' => $this->anrede($notifiable),
            'anmeldenUrl' => route('login'),
            'passwortUrl' => route('password.request'),
        ];

        return (new MailMessage)
            ->subject('Zu Ihrer E-Mail-Adresse besteht bereits ein Konto')
            ->view(
                ['emails.auth.konto-bereits-vorhanden', 'emails.auth.konto-bereits-vorhanden-text'],
                $daten
            );
    }

    private function anrede(object $notifiable): string
    {
        if (! $notifiable instanceof User) {
            return 'Guten Tag,';
        }

        $name = $notifiable->getAttribute('name');

        return is_string($name) && trim($name) !== ''
            ? 'Guten Tag '.trim($name).','
            : 'Guten Tag,';
    }
}
