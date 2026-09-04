<?php

declare(strict_types=1);

namespace App\Mail;

use App\Application\Account\AuditRecorder;
use App\Enums\EmailStatus;
use App\Enums\GeneratedDocumentKind;
use App\Models\BillingRun;
use App\Models\EmailMessage;
use App\Models\User;
use Carbon\CarbonInterface;
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
 *  5. Ein zeitweiliger Fehler (Postausgang nicht erreichbar, Anmeldung,
 *     Greylisting) wird bis zu MAX_VERSUCHE Mal innerhalb von
 *     WIEDERHOLUNGSFENSTER_STUNDEN erneut versucht: durch den Zeitplan
 *     (smartabrechnen:retry-failed-emails) oder durch eine Adminhandlung in
 *     der Kommunikationsuebersicht. Dafuer haelt das Protokoll die Nachricht
 *     verschluesselt im Wiederholungspuffer retry_payload, der nach Erfolg,
 *     bei dauerhaftem Fehler und nach Ablauf des Fensters geleert wird.
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

    public const AUDIT_ERNEUT_GESENDET = 'email.resent';

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

        return $this->versende($protokoll, $mail, $adresse, $nutzer, $organizationId, 1);
    }

    /**
     * Hoechstzahl der Versuche je Nachricht, Erstversand eingeschlossen.
     */
    public const MAX_VERSUCHE = 3;

    /**
     * Zeitfenster ab Erstversand, in dem eine zeitweilig gescheiterte
     * Nachricht erneut versendet wird. Danach ist der Anlass veraltet und der
     * Wiederholungspuffer wird geleert.
     */
    public const WIEDERHOLUNGSFENSTER_STUNDEN = 24;

    /**
     * Versendet eine zeitweilig gescheiterte Nachricht erneut.
     *
     * Wiederholt wird ausschliesslich eine Nachricht im Status FEHLGESCHLAGEN
     * mit vorhandenem Wiederholungspuffer, unterhalb von MAX_VERSUCHE und
     * innerhalb von WIEDERHOLUNGSFENSTER_STUNDEN. Eine dauerhaft unzustellbare
     * (BOUNCED) oder unterdrueckte Nachricht wird nie wiederholt. Eine
     * inzwischen gesperrte Adresse erhaelt keine gewoehnliche Nachricht mehr.
     *
     * @param  User|null  $akteur  Handelnde Person bei manueller Wiederholung,
     *                             null im Zeitplan.
     *
     * @throws WiederholungNichtMoeglichException
     */
    public function erneutSenden(EmailMessage $protokoll, ?User $akteur = null): EmailMessage
    {
        $grund = $this->wiederholungsHindernis($protokoll);

        if ($grund !== null) {
            throw new WiederholungNichtMoeglichException($grund);
        }

        $mail = $this->nachrichtAusPuffer($protokoll);

        if (! $mail instanceof TransactionalMail) {
            $protokoll->forceFill(['retry_payload' => null])->save();

            throw new WiederholungNichtMoeglichException(
                'Der Wiederholungspuffer der Nachricht ist nicht lesbar. Die Nachricht wird nicht erneut versendet.'
            );
        }

        $adresse = (string) $protokoll->getAttribute('recipient_email');
        $organizationId = $protokoll->getAttribute('organization_id');
        $organizationId = is_string($organizationId) && $organizationId !== '' ? $organizationId : null;
        /** @var User|null $nutzer */
        $nutzer = $protokoll->getRelationValue('user');
        $versuch = (int) $protokoll->getAttribute('attempts') + 1;

        if (! $mail->istKritisch() && $this->suppression->isSuppressed($adresse)) {
            $protokoll->forceFill([
                'status' => EmailStatus::UNTERDRUECKT,
                'error_code' => 'ADRESSE_GESPERRT',
                'error_message' => 'Die Adresse steht auf der Sperrliste. Es wurde nicht erneut versendet.',
                'retry_payload' => null,
            ])->save();

            $this->audit->record(
                action: self::AUDIT_UNTERDRUECKT,
                subject: $protokoll,
                actor: $akteur ?? $nutzer,
                organization: $organizationId,
                metadata: ['template' => $mail->template(), 'versuch' => $versuch],
            );

            return $protokoll;
        }

        return $this->versende($protokoll, $mail, $adresse, $nutzer, $organizationId, $versuch, $akteur);
    }

    /**
     * Grund, warum die Nachricht nicht erneut versendet wird, oder null.
     */
    public function wiederholungsHindernis(EmailMessage $protokoll): ?string
    {
        if ($protokoll->getAttribute('status') !== EmailStatus::FEHLGESCHLAGEN) {
            return 'Nur eine zeitweilig gescheiterte Nachricht wird erneut versendet.';
        }

        if ((int) $protokoll->getAttribute('attempts') >= self::MAX_VERSUCHE) {
            return sprintf('Die Nachricht wurde bereits %d Mal versucht und wird nicht erneut versendet.', self::MAX_VERSUCHE);
        }

        $begonnen = $protokoll->getAttribute('queued_at') ?? $protokoll->getAttribute('created_at');

        if (! $begonnen instanceof CarbonInterface || $begonnen->lt(now()->subHours(self::WIEDERHOLUNGSFENSTER_STUNDEN))) {
            return sprintf('Die Nachricht ist aelter als %d Stunden. Der Anlass ist veraltet, sie wird nicht erneut versendet.', self::WIEDERHOLUNGSFENSTER_STUNDEN);
        }

        $puffer = $protokoll->getAttribute('retry_payload');

        if (! is_string($puffer) || $puffer === '') {
            return 'Zu dieser Nachricht liegt kein Wiederholungspuffer vor.';
        }

        return null;
    }

    public function istWiederholbar(EmailMessage $protokoll): bool
    {
        return $this->wiederholungsHindernis($protokoll) === null;
    }

    /**
     * Fuehrt einen Versuch aus und schreibt Protokoll, Audit und Sperre.
     */
    private function versende(
        EmailMessage $protokoll,
        TransactionalMail $mail,
        string $adresse,
        ?User $nutzer,
        ?string $organizationId,
        int $versuch,
        ?User $akteur = null,
    ): EmailMessage {
        try {
            /** @var SentMessage|null $gesendet */
            $gesendet = Mail::to($adresse)->send($mail);

            $protokoll->forceFill([
                'status' => EmailStatus::GESENDET,
                'attempts' => $versuch,
                'sent_at' => now(),
                'message_id' => $this->messageId($gesendet),
                'error_code' => null,
                'error_message' => null,
                'retry_payload' => null,
            ])->save();

            $this->audit->record(
                action: $versuch > 1 ? self::AUDIT_ERNEUT_GESENDET : self::AUDIT_GESENDET,
                subject: $protokoll,
                actor: $akteur ?? $nutzer,
                organization: $organizationId,
                metadata: ['template' => $mail->template(), 'versuch' => $versuch],
            );

            return $protokoll;
        } catch (Throwable $fehler) {
            $dauerhaft = self::istDauerhafterFehler($fehler, $adresse);

            $protokoll->forceFill([
                'status' => $dauerhaft ? EmailStatus::BOUNCED : EmailStatus::FEHLGESCHLAGEN,
                'attempts' => $versuch,
                'failed_at' => now(),
                'error_code' => class_basename($fehler),
                'error_message' => self::fehlertext($fehler),
                // Nur ein zeitweiliger Fehler wird wiederholt. Ist die Hoechstzahl
                // erreicht, braucht es keinen Puffer mehr.
                'retry_payload' => ! $dauerhaft && $versuch < self::MAX_VERSUCHE
                    ? $this->puffer($mail)
                    : null,
            ])->save();

            $this->audit->record(
                action: self::AUDIT_FEHLGESCHLAGEN,
                subject: $protokoll,
                actor: $akteur ?? $nutzer,
                organization: $organizationId,
                metadata: [
                    'template' => $mail->template(),
                    'dauerhaft' => $dauerhaft,
                    'versuch' => $versuch,
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
     * Serialisierte Nachricht fuer den Wiederholungspuffer. Laesst sich die
     * Nachricht nicht serialisieren, gibt es keine Wiederholung; der Fehler
     * selbst bleibt protokolliert.
     */
    private function puffer(TransactionalMail $mail): ?string
    {
        try {
            return serialize($mail);
        } catch (Throwable) {
            return null;
        }
    }

    private function nachrichtAusPuffer(EmailMessage $protokoll): ?TransactionalMail
    {
        $puffer = $protokoll->getAttribute('retry_payload');

        if (! is_string($puffer) || $puffer === '') {
            return null;
        }

        try {
            // Der Puffer stammt verschluesselt aus der eigenen Datenbank; eine
            // fremde Nutzlast kann hier nicht ankommen.
            $mail = unserialize($puffer, ['allowed_classes' => true]);
        } catch (Throwable) {
            return null;
        }

        return $mail instanceof TransactionalMail ? $mail : null;
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
     * Wortlaute, an denen erkennbar ist, dass die Servermeldung den EMPFAENGER
     * betrifft. Dazu zaehlen die erweiterten Statuscodes nach RFC 3463 fuer
     * Zieladresse und Zielpostfach (5.1.1 bis 5.1.6, 5.2.x).
     */
    private const EMPFAENGERMERKMALE = '/Recipient|User unknown|Unknown user|No such user|does not exist|mailbox|Empf(ä|ae)nger|Postfach|\b5\.(1\.[1-6]|2\.\d+)\b/iu';

    /**
     * Ein dauerhafter Zustellfehler ist ausschliesslich eine Antwort der
     * Gegenstelle auf RCPT oder DATA mit einem Code aus
     * DAUERHAFTE_EMPFAENGERCODES, deren Wortlaut erkennbar den Empfaenger
     * betrifft, zum Beispiel 550 unbekannter Empfaenger.
     *
     * Absenderseitige Fehler duerfen die Adresse nie sperren: Ein nicht
     * erreichbarer Postausgangsserver (Verbindungsfehler, die Meldung nennt
     * Host und Port wie "smtp.example:587"), ein falsches Postfachpasswort
     * (Code 535, 534, 530 bei der Anmeldung) oder eine Zeitueberschreitung
     * sagen nichts ueber die Empfaengeradresse aus. Dasselbe gilt fuer 5xx-
     * Antworten, die den Absender, den einliefernden Host, das Relay oder die
     * Nachrichtengroesse betreffen ("Sender address rejected", "Client host
     * blocked", "Relay access denied", "message size exceeds"): Sie tragen
     * denselben Code wie eine Empfaengerablehnung, sagen aber nichts ueber die
     * Adresse aus. Sie sind zeitweilig und fuehren nicht zur Sperrung, ebenso
     * alle 4xx-Antworten.
     *
     * Symfony Mailer formuliert Serverantworten als
     * 'Expected response code "250" but got code "550", with message "..."'
     * und Anmeldefehler als 'Failed to authenticate on SMTP server ...'.
     * Ausgewertet wird deshalb der ausdrueckliche Antwortcode, nicht jede
     * dreistellige Zahl in der Meldung, und zusaetzlich der Wortlaut.
     *
     * @param  string|null  $empfaenger  Adresse des Empfaengers; nennt die
     *                                   Meldung sie, betrifft sie den Empfaenger.
     */
    public static function istDauerhafterFehler(Throwable $fehler, ?string $empfaenger = null): bool
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

        if (! in_array((int) $treffer[1], self::DAUERHAFTE_EMPFAENGERCODES, true)) {
            return false;
        }

        return self::betrifftEmpfaenger($meldung, $empfaenger);
    }

    private static function betrifftEmpfaenger(string $meldung, ?string $empfaenger): bool
    {
        if ($empfaenger !== null && $empfaenger !== '' && mb_stripos($meldung, $empfaenger) !== false) {
            return true;
        }

        return preg_match(self::EMPFAENGERMERKMALE, $meldung) === 1;
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
