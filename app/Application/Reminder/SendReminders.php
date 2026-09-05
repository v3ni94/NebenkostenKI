<?php

declare(strict_types=1);

namespace App\Application\Reminder;

use App\Application\Account\AuditRecorder;
use App\Enums\BillingRunStatus;
use App\Enums\EmailStatus;
use App\Enums\ReminderStatus;
use App\Enums\ReminderWindow;
use App\Enums\UserStatus;
use App\Mail\ErinnerungFolgejahrMail;
use App\Mail\MailDispatcher;
use App\Mail\SuppressionGuard;
use App\Models\EmailMessage;
use App\Models\Property;
use App\Models\ReminderEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Automatische Erinnerungen fuer Folgejahre (Masterprompt 17).
 *
 * Fuer jedes aktive Objekt ist im Folgejahr ein Zyklus vorgemerkt. Solange die
 * Abrechnung fuer das relevante Vorjahr nicht finalisiert und die Erinnerung
 * nicht deaktiviert ist, wird an vier Terminen erinnert.
 *
 * VERBINDLICHE REGELN, jede einzelne mit Test:
 *
 *  1. Keine Erinnerung bei bereits finalisiertem Jahreslauf.
 *  2. Keine Dublette innerhalb eines Erinnerungsfensters. Die Sperre liegt im
 *     eindeutigen deduplication_key der Datenbank und nicht nur im PHP-Code.
 *     Ein mehrfacher Cronlauf am selben Tag erzeugt deshalb keine zweite Mail.
 *  3. Der CTA oeffnet direkt den vorausgefuellten Folgejahreslauf.
 *  4. Nutzer koennen global und je Objekt deaktivieren und wieder aktivieren.
 *  5. Jede Erinnerung enthaelt einen sicheren Abmeldelink ohne Anmeldung.
 *  6. Versand, Unterdrueckung, Abmeldung und Reaktivierung werden protokolliert.
 *  7. Geloeschte, gesperrte und unbestaetigte Konten erhalten keine Erinnerung.
 *  8. Eine gesperrte Adresse erhaelt keine Erinnerung mehr.
 *
 * Der Lauf ist idempotent und ohne dauerhaften Worker ausfuehrbar.
 */
class SendReminders
{
    public const AUDIT_GESENDET = 'reminder.sent';

    public const AUDIT_UNTERDRUECKT = 'reminder.suppressed';

    public function __construct(
        private readonly ReminderSchedule $plan,
        private readonly ReminderPreferences $einstellungen,
        private readonly ReminderLinks $links,
        private readonly MailDispatcher $mailer,
        private readonly SuppressionGuard $sperrliste,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Fuehrt den Lauf fuer einen Kalendertag aus.
     */
    public function fuerTag(?CarbonImmutable $tag = null, ?int $limit = null): ReminderReport
    {
        $stichtag = ($tag ?? CarbonImmutable::now())->setTimezone(ReminderSchedule::ZEITZONE);

        if (! $this->plan->istAktiv()) {
            return new ReminderReport(null);
        }

        $fenster = $this->plan->fensterAm($stichtag);

        if ($fenster === null) {
            return new ReminderReport(null);
        }

        $bericht = new ReminderReport($fenster->value);
        $jahr = $this->plan->abrechnungsjahr($stichtag);

        $objekte = Property::query()
            ->where('is_active', true)
            ->orderBy('created_at')
            ->when($limit !== null && $limit > 0, fn ($query) => $query->limit($limit))
            ->get();

        foreach ($objekte as $objekt) {
            $bericht->geprueft++;
            $this->behandleObjekt($objekt, $fenster, $jahr, $stichtag, $bericht);
        }

        return $bericht;
    }

    private function behandleObjekt(
        Property $objekt,
        ReminderWindow $fenster,
        int $jahr,
        CarbonImmutable $stichtag,
        ReminderReport $bericht,
    ): void {
        $empfaenger = $this->empfaenger($objekt);

        if (! $empfaenger instanceof User) {
            $bericht->zaehleUebersprungen(ReminderReport::GRUND_KEIN_EMPFAENGER);

            return;
        }

        // Geloeschte und gesperrte Konten erhalten keine Erinnerung.
        if ($empfaenger->getAttribute('deleted_at') !== null
            || $empfaenger->getAttribute('status') !== UserStatus::AKTIV) {
            $bericht->zaehleUebersprungen(ReminderReport::GRUND_KONTO_NICHT_AKTIV);

            return;
        }

        // Unbestaetigte Adressen erhalten keine Erinnerung.
        if ($empfaenger->getAttribute('email_verified_at') === null) {
            $bericht->zaehleUebersprungen(ReminderReport::GRUND_NICHT_BESTAETIGT);

            return;
        }

        if (! $this->einstellungen->darfErinnern($empfaenger, $objekt, $fenster)) {
            $bericht->zaehleUebersprungen(ReminderReport::GRUND_ABGEMELDET);

            return;
        }

        if ($this->istFinalisiert($objekt, $jahr)) {
            $bericht->zaehleUebersprungen(ReminderReport::GRUND_FINALISIERT);

            return;
        }

        $adresse = SuppressionGuard::normalize((string) $empfaenger->getAttribute('email'));

        $schluessel = ReminderEvent::buildDeduplicationKey(
            (string) $empfaenger->getKey(),
            (string) $objekt->getKey(),
            $jahr,
            $fenster
        );

        if (ReminderEvent::query()->where('deduplication_key', $schluessel)->exists()) {
            $bericht->zaehleUebersprungen(ReminderReport::GRUND_DUBLETTE);

            return;
        }

        try {
            /** @var ReminderEvent $ereignis */
            $ereignis = ReminderEvent::query()->create([
                'organization_id' => $objekt->getAttribute('organization_id'),
                'user_id' => $empfaenger->getKey(),
                'property_id' => $objekt->getKey(),
                'billing_year' => $jahr,
                'reminder_window' => $fenster,
                'recipient_email' => $adresse,
                'deduplication_key' => $schluessel,
                'status' => ReminderStatus::GEPLANT,
                // In der Anwendungszeitzone Europe/Berlin (ADR-018), nicht in UTC:
                // Eloquent speichert Zeitstempel in der Anwendungszeitzone, ein
                // UTC-Wert wuerde in Anzeige und scopeDue um ein bis zwei
                // Stunden verschoben gelesen.
                'scheduled_for' => $stichtag,
            ]);
        } catch (QueryException) {
            // Zwei gleichzeitige Cronlaeufe. Der eindeutige Schluessel hat die
            // Dublette verhindert, das ist der gewuenschte Ausgang.
            $bericht->zaehleUebersprungen(ReminderReport::GRUND_DUBLETTE);

            return;
        }

        if ($this->sperrliste->isSuppressed($adresse)) {
            $ereignis->forceFill([
                'status' => ReminderStatus::UNTERDRUECKT,
                'suppressed_reason' => 'Die Adresse steht auf der Sperrliste.',
            ])->save();

            $this->audit->record(
                action: self::AUDIT_UNTERDRUECKT,
                subject: $ereignis,
                actor: $empfaenger,
                organization: $this->organisation($objekt),
                metadata: [
                    'fenster' => $fenster->value,
                    'jahr' => $jahr,
                    'grund' => ReminderReport::GRUND_ADRESSE_GESPERRT,
                ],
            );

            $bericht->zaehleUebersprungen(ReminderReport::GRUND_ADRESSE_GESPERRT);

            return;
        }

        $einstellung = $this->einstellungen->objektEinstellung($empfaenger, $objekt);

        $mail = new ErinnerungFolgejahrMail(
            anrede: $this->anrede($empfaenger),
            objekt: (string) $objekt->getAttribute('label'),
            jahr: $jahr,
            fenster: $fenster,
            startUrl: $this->links->folgejahrUrl($objekt, $jahr),
            abmeldeUrl: $this->links->abmeldeUrl($einstellung),
        );

        $protokoll = $this->mailer->send(
            mail: $mail,
            empfaenger: $adresse,
            nutzer: $empfaenger,
            organizationId: $this->organisation($objekt),
        );

        $this->uebernehmeVersandstand($ereignis, $protokoll);

        if ($ereignis->getAttribute('status') === ReminderStatus::GESENDET) {
            $bericht->gesendet++;

            $this->audit->record(
                action: self::AUDIT_GESENDET,
                subject: $ereignis,
                actor: $empfaenger,
                organization: $this->organisation($objekt),
                metadata: [
                    'fenster' => $fenster->value,
                    'jahr' => $jahr,
                ],
            );

            return;
        }

        $bericht->zaehleUebersprungen(ReminderReport::GRUND_VERSAND_FEHLGESCHLAGEN);
    }

    private function uebernehmeVersandstand(ReminderEvent $ereignis, EmailMessage $protokoll): void
    {
        $status = $protokoll->getAttribute('status');

        $ereignis->forceFill([
            'email_message_id' => $protokoll->getKey(),
            'status' => match ($status) {
                EmailStatus::GESENDET => ReminderStatus::GESENDET,
                EmailStatus::UNTERDRUECKT => ReminderStatus::UNTERDRUECKT,
                default => ReminderStatus::FEHLGESCHLAGEN,
            },
            'sent_at' => $status === EmailStatus::GESENDET ? now() : null,
            'suppressed_reason' => $status === EmailStatus::UNTERDRUECKT
                ? 'Die Adresse steht auf der Sperrliste.'
                : $ereignis->getAttribute('suppressed_reason'),
        ])->save();
    }

    /**
     * Empfaenger ist der Nutzer, der das Objekt angelegt hat. Er besitzt das
     * dauerhafte Konto zu diesem Objekt (Masterprompt 8.2).
     */
    private function empfaenger(Property $objekt): ?User
    {
        $nutzerId = $objekt->getAttribute('created_by_user_id');

        if (! is_string($nutzerId) || $nutzerId === '') {
            return null;
        }

        /** @var User|null $nutzer */
        $nutzer = User::withTrashed()->whereKey($nutzerId)->first();

        return $nutzer;
    }

    private function istFinalisiert(Property $objekt, int $jahr): bool
    {
        return $objekt->billingRuns()
            ->where('billing_year', $jahr)
            ->where('status', BillingRunStatus::FINALIZED->value)
            ->exists();
    }

    private function organisation(Property $objekt): ?string
    {
        $wert = $objekt->getAttribute('organization_id');

        return is_string($wert) && $wert !== '' ? $wert : null;
    }

    private function anrede(User $nutzer): string
    {
        $name = $nutzer->getAttribute('name');

        return is_string($name) && trim($name) !== ''
            ? 'Guten Tag '.trim($name).','
            : 'Guten Tag,';
    }
}
