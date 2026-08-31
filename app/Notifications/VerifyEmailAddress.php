<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Account\EmailVerification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Bestaetigung der E-Mail-Adresse.
 *
 * Die Nachricht wird als HTML und als reiner Text versendet. Der Absender kommt
 * ausschliesslich aus der Mailkonfiguration (config/mail.php, MAIL_FROM_*), es
 * wird keine Adresse im Code gesetzt.
 *
 * Der Link ist signiert und kurzlebig. Er wird nicht in Logs geschrieben.
 */
class VerifyEmailAddress extends Notification
{
    use Queueable;

    public function __construct(private readonly string $url) {}

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
            'url' => $this->url,
            'gueltigkeitMinuten' => EmailVerification::LINK_GUELTIGKEIT_MINUTEN,
            'anrede' => $this->anrede($notifiable),
        ];

        return (new MailMessage)
            ->subject('Bitte bestätigen Sie Ihre E-Mail-Adresse')
            ->view(
                ['emails.auth.verifizierung', 'emails.auth.verifizierung-text'],
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
