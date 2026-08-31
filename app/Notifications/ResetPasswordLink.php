<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * Link zum Zuruecksetzen des Passworts.
 *
 * Vorgabe des Masterprompts, Abschnitt 8.1: Passwort-Reset mit kurzlebigem
 * Einmal-Token. Die Gueltigkeit steht in config/auth.php unter
 * passwords.users.expire und betraegt standardmaessig 30 Minuten. Das Token
 * wird nach der Verwendung geloescht.
 *
 * Die Nachricht wird als HTML und als reiner Text versendet. Der Absender kommt
 * ausschliesslich aus der Mailkonfiguration.
 */
class ResetPasswordLink extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $email = $notifiable instanceof User ? $notifiable->getAttribute('email') : null;

        $url = route('password.reset', [
            'token' => $this->token,
            'email' => is_string($email) ? $email : '',
        ]);

        $gueltigkeit = Config::get('auth.passwords.users.expire', 30);

        return (new MailMessage)
            ->subject('Passwort für Smart Abrechnen zurücksetzen')
            ->view(
                ['emails.auth.passwort-zuruecksetzen', 'emails.auth.passwort-zuruecksetzen-text'],
                [
                    'url' => $url,
                    'gueltigkeitMinuten' => is_numeric($gueltigkeit) ? (int) $gueltigkeit : 30,
                    'anrede' => $this->anrede($notifiable),
                ]
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
