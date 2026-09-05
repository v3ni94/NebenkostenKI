<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\EmailSuppressionReason;
use App\Models\EmailSuppression;
use Illuminate\Support\Str;

/**
 * Sperrliste der Empfaengeradressen.
 *
 * Eine gesperrte Adresse erhaelt keine Erinnerungen mehr. Kritische Konto- und
 * Zahlungsnachrichten bleiben davon unberuehrt (Masterprompt 17.2), weil der
 * Nutzer Zahlungsstand, Rechnung und Fehler kennen muss.
 *
 * Adressen werden ausschliesslich in normalisierter Form verglichen: getrimmt
 * und in Kleinbuchstaben. Sonst wuerde eine Sperre durch eine andere
 * Schreibweise umgangen.
 */
class SuppressionGuard
{
    public static function normalize(string $email): string
    {
        return Str::lower(trim($email));
    }

    public function isSuppressed(string $email): bool
    {
        return $this->find($email) instanceof EmailSuppression;
    }

    public function find(string $email): ?EmailSuppression
    {
        /** @var EmailSuppression|null $eintrag */
        $eintrag = EmailSuppression::query()
            ->where('email', self::normalize($email))
            ->first();

        return $eintrag;
    }

    /**
     * Sperrt eine Adresse. Der Aufruf ist idempotent, eine bestehende Sperre
     * behaelt ihren ersten Grund und ihren ersten Zeitpunkt.
     */
    public function suppress(
        string $email,
        EmailSuppressionReason $reason,
        ?string $source = null,
        ?string $note = null,
    ): EmailSuppression {
        $bestehend = $this->find($email);

        if ($bestehend instanceof EmailSuppression) {
            return $bestehend;
        }

        /** @var EmailSuppression $eintrag */
        $eintrag = EmailSuppression::query()->create([
            'email' => self::normalize($email),
            'reason' => $reason,
            'suppressed_at' => now(),
            'source' => $source,
            'note' => $note,
        ]);

        return $eintrag;
    }

    /**
     * Hebt eine Sperre auf, zum Beispiel nach einer erneuten Anmeldung zu den
     * Erinnerungen. Gibt true zurueck, wenn eine Sperre bestand.
     */
    public function release(string $email): bool
    {
        $eintrag = $this->find($email);

        if (! $eintrag instanceof EmailSuppression) {
            return false;
        }

        $eintrag->delete();

        return true;
    }

    /**
     * Hinweis fuer die Kontoseite. Er nennt den Sachverhalt und die naechste
     * Handlung, ohne technische Rohdaten.
     */
    public function hinweisFuerKonto(string $email): ?string
    {
        $eintrag = $this->find($email);

        if (! $eintrag instanceof EmailSuppression) {
            return null;
        }

        $grund = $eintrag->getAttribute('reason');
        $seit = Format::datum($eintrag->getAttribute('suppressed_at'));

        if ($grund === EmailSuppressionReason::ABMELDUNG) {
            return sprintf(
                'Sie haben die Erinnerungen am %s abgemeldet. Sie können sie in Ihrem Konto '
                .'jederzeit wieder aktivieren.',
                $seit
            );
        }

        return sprintf(
            'An Ihre E-Mail-Adresse konnte am %s nicht zugestellt werden. Wir versenden deshalb keine '
            .'Erinnerungen mehr an diese Adresse. Bitte prüfen Sie die Adresse in Ihrem Konto und '
            .'hinterlegen Sie bei Bedarf eine andere. Nachrichten zu Zahlung und Rechnung erhalten Sie '
            .'weiterhin.',
            $seit
        );
    }
}
