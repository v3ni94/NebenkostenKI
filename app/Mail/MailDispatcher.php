<?php

declare(strict_types=1);

namespace App\Mail;

use App\Application\Account\AuditRecorder;
use App\Enums\EmailStatus;
use App\Enums\GeneratedDocumentKind;
use App\Models\BillingRun;
use App\Models\EmailMessage;
use App\Models\User;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Zentraler Versand und Protokoll aller Transaktionsmails.
 *
 * VERBINDLICHE REGELN:
 *
 *  1. Jeder Versand wird in email_messages protokolliert: Template, Empfaenger,
 *     Status, Message-ID, Versuch und Fehler. Protokolliert werden niemals
 *     Passwoerter, Tokens, Downloadlinks oder vertrauliche Inhalte
 *     (Masterprompt 10, 16, 19).
 *  2. Eine unterdrueckte Adresse erhaelt keine gewoehnliche Nachricht mehr.
 *     Kritische Konto- und Zahlungsnachrichten werden weiterhin versendet
 *     (Masterprompt 17.2).
 *  3. Als Anhang ist ausschliesslich die HVM-Leistungsrechnung zulaessig. Eine
 *     Mieterabrechnung wird nie angehaengt, sondern ueber einen zeitlich
 *     begrenzten kontogebundenen Downloadlink bereitgestellt.
 *  4. Ein dauerhafter Zustellfehler fuehrt zur Sperrung der Adresse und zu
 *     einem Hinweis im Konto.
 *
 * Der Absender kommt ausschliesslich aus der Mailkonfiguration.
 */
class MailDispatcher
{
    public function __construct(
        private readonly SuppressionGuard $suppression,
        private readonly BounceHandler $bounces,
        private readonly AuditRecorder $audit,
    ) {}

    public const AUDIT_GESENDET = 'email.sent';

    public const AUDIT_UNTERDRUECKT = 'email.suppressed';

    public const AUDIT_FEHLGESCHLAGEN = 'email.failed';

    /**
     * Versendet eine Nachricht und gibt das Protokoll zurueck.
     */
    public function send(
        TransactionalMail $mail,
        string $empfaenger,
        ?User $nutzer = null,
        ?string $organizationId = null,
        ?BillingRun $lauf = null,
    ): EmailMessage {
        $this->assertZulaessigeAnhaenge($mail);

        $adresse = SuppressionGuard::normalize($empfaenger);

        /** @var EmailMessage $protokoll */
        $protokoll = EmailMessage::query()->create([
            'organization_id' => $organizationId,
            'user_id' => $nutzer?->getKey(),
            'billing_run_id' => $lauf?->getKey(),
            'template' => $mail->template(),
            'recipient_email' => $adresse,
            'subject' => Str::limit($mail->betreff(), 185, ''),
            'status' => EmailStatus::WARTEND,
            'attempts' => 0,
            'queued_at' => now(),
        ]);

        if (! $mail->istKritisch() && $this->suppression->isSuppressed($adresse)) {
            $protokoll->forceFill([
                'status' => EmailStatus::UNTERDRUECKT,
                'error_code' => 'ADRESSE_GESPERRT',
                'error_message' => 'Die Adresse steht auf der Sperrliste. Es wurde nicht versendet.',
            ])->save();

            $this->audit->record(
                action: self::AUDIT_UNTERDRUECKT,
                subject: $protokoll,
                actor: $nutzer,
                organization: $organizationId,
                metadata: ['template' => $mail->template()],
            );

            return $protokoll;
        }

        try {
            /** @var SentMessage|null $gesendet */
            $gesendet = Mail::to($adresse)->send($mail);

            $protokoll->forceFill([
                'status' => EmailStatus::GESENDET,
                'attempts' => 1,
                'sent_at' => now(),
                'message_id' => $this->messageId($gesendet),
            ])->save();

            $this->audit->record(
                action: self::AUDIT_GESENDET,
                subject: $protokoll,
                actor: $nutzer,
                organization: $organizationId,
                metadata: ['template' => $mail->template()],
            );

            return $protokoll;
        } catch (Throwable $fehler) {
            $dauerhaft = self::istDauerhafterFehler($fehler);

            $protokoll->forceFill([
                'status' => $dauerhaft ? EmailStatus::BOUNCED : EmailStatus::FEHLGESCHLAGEN,
                'attempts' => 1,
                'failed_at' => now(),
                'error_code' => class_basename($fehler),
                'error_message' => self::fehlertext($fehler),
            ])->save();

            $this->audit->record(
                action: self::AUDIT_FEHLGESCHLAGEN,
                subject: $protokoll,
                actor: $nutzer,
                organization: $organizationId,
                metadata: [
                    'template' => $mail->template(),
                    'dauerhaft' => $dauerhaft,
                ],
            );

            if ($dauerhaft) {
                $this->bounces->handlePermanentFailure(
                    email: $adresse,
                    source: $mail->template(),
                    nutzer: $nutzer,
                    organizationId: $organizationId,
                );
            }

            return $protokoll;
        }
    }

    /**
     * SMTP-Antwortcodes der Gegenstelle, die den Empfaenger dauerhaft ablehnen
     * (RFC 5321, Abschnitt 4.2.3): 550 Postfach nicht verfuegbar, 551 Nutzer
     * nicht lokal, 552 Speicher ueberschritten, 553 Postfachname unzulaessig,
     * 554 Transaktion fehlgeschlagen.
     *
     * @var list<int>
     */
    private const DAUERHAFTE_EMPFAENGERCODES = [550, 551, 552, 553, 554];

    /**
     * Ein dauerhafter Zustellfehler ist ausschliesslich eine Antwort der
     * Gegenstelle auf RCPT oder DATA mit einem Code aus
     * DAUERHAFTE_EMPFAENGERCODES, zum Beispiel 550 unbekannter Empfaenger.
     *
     * Absenderseitige Fehler duerfen die Adresse nie sperren: Ein nicht
     * erreichbarer Postausgangsserver (Verbindungsfehler, die Meldung nennt
     * Host und Port wie "smtp.example:587"), ein falsches Postfachpasswort
     * (Code 535, 534, 530 bei der Anmeldung) oder eine Zeitueberschreitung
     * sagen nichts ueber die Empfaengeradresse aus. Sie sind zeitweilig und
     * fuehren nicht zur Sperrung, ebenso alle 4xx-Antworten.
     *
     * Symfony Mailer formuliert Serverantworten als
     * 'Expected response code "250" but got code "550", with message "..."'
     * und Anmeldefehler als 'Failed to authenticate on SMTP server ...'.
     * Ausgewertet wird deshalb der ausdrueckliche Antwortcode, nicht jede
     * dreistellige Zahl in der Meldung.
     */
    public static function istDauerhafterFehler(Throwable $fehler): bool
    {
        if ($fehler instanceof PermanenterZustellfehlerException) {
            return true;
        }

        $meldung = $fehler->getMessage();

        if (preg_match('/Failed to authenticate|Connection could not be established|Connection to .* has been closed|Connection .* timed out/i', $meldung) === 1) {
            return false;
        }

        // Antwortcode der Gegenstelle, wie ihn Symfony Mailer wiedergibt, oder
        // eine Meldung, die direkt mit dem Antwortcode beginnt (550 5.1.1 ...).
        if (preg_match('/got code "(\d{3})"/', $meldung, $treffer) !== 1
            && preg_match('/^\s*(\d{3})(?=[\s\-])/', $meldung, $treffer) !== 1) {
            return false;
        }

        return in_array((int) $treffer[1], self::DAUERHAFTE_EMPFAENGERCODES, true);
    }

    /**
     * Fehlertext fuer das Protokoll.
     *
     * Der Text ist gekuerzt und redigiert. Zugangsdaten aus der
     * Mailkonfiguration werden ersetzt, damit sie nicht in der Datenbank
     * landen.
     */
    private static function fehlertext(Throwable $fehler): string
    {
        $text = $fehler->getMessage();

        foreach (['username', 'password'] as $schluessel) {
            $wert = config('mail.mailers.smtp.'.$schluessel);

            if (is_string($wert) && $wert !== '') {
                $text = str_replace($wert, '[redigiert]', $text);
            }
        }

        return Str::limit($text, 480, '');
    }

    private function messageId(?SentMessage $gesendet): string
    {
        $id = $gesendet?->getMessageId();

        if (is_string($id) && $id !== '') {
            return Str::limit($id, 185, '');
        }

        // Ohne Providerantwort wird eine eigene Kennung vergeben, damit das
        // Protokoll eindeutig bleibt und ein Vorgang zuordenbar ist.
        return sprintf('lokal-%s', (string) Str::ulid());
    }

    /**
     * @throws UnzulaessigerAnhangException
     */
    private function assertZulaessigeAnhaenge(TransactionalMail $mail): void
    {
        foreach ($mail->anhangDokumente() as $dokument) {
            if ($dokument->getAttribute('kind') !== GeneratedDocumentKind::HVM_RECHNUNG) {
                throw new UnzulaessigerAnhangException(
                    'Als Anhang ist ausschließlich die Leistungsrechnung zulässig. '
                    .'Abrechnungen werden über einen zeitlich begrenzten Downloadlink bereitgestellt.'
                );
            }
        }
    }
}
