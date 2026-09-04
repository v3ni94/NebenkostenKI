<?php

declare(strict_types=1);

namespace Tests\Feature\Auth\Concerns;

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Ersetzt den Mailversand durch einen Transport, der wie ein nicht
 * erreichbarer SMTP-Server scheitert.
 *
 * Mail::fake() und Notification::fake() sehen den Fehlerpfad nicht, weil sie
 * den Versand gar nicht ausfuehren. Erst ein echter, scheiternder Transport
 * zeigt, ob die Anwendung mit HTTP 500 antwortet oder den Fehler abfaengt.
 */
trait SimuliertMailausfall
{
    protected function simuliereMailausfall(): void
    {
        Mail::extend('smtp-ausfall', static function (): AbstractTransport {
            return new class extends AbstractTransport
            {
                protected function doSend(SentMessage $message): void
                {
                    throw new TransportException('Connection could not be established with host smtp.invalid');
                }

                public function __toString(): string
                {
                    return 'smtp-ausfall://';
                }
            };
        });

        config([
            'mail.mailers.smtp-ausfall' => ['transport' => 'smtp-ausfall'],
            'mail.default' => 'smtp-ausfall',
        ]);

        Mail::forgetMailers();
    }
}
