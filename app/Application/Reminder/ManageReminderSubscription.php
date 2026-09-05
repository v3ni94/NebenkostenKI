<?php

declare(strict_types=1);

namespace App\Application\Reminder;

use App\Application\Account\AuditRecorder;
use App\Enums\EmailSuppressionReason;
use App\Mail\SuppressionGuard;
use App\Models\EmailSuppression;
use App\Models\Property;
use App\Models\ReminderPreference;
use App\Models\User;

/**
 * Abmeldung und erneute Aktivierung der Erinnerungen.
 *
 * Der Vorgang ist ueber den signierten Abmeldelink ohne Anmeldung erreichbar
 * (Masterprompt 17.2). Er wirkt ausschliesslich auf Erinnerungen. Kritische
 * Konto- und Zahlungsnachrichten bleiben unberuehrt.
 *
 * Abmeldung und Reaktivierung werden revisionssicher protokolliert.
 */
class ManageReminderSubscription
{
    public const AUDIT_ABMELDUNG = 'reminder.unsubscribed';

    public const AUDIT_REAKTIVIERUNG = 'reminder.resubscribed';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly SuppressionGuard $sperrliste,
    ) {}

    public function abmelden(ReminderPreference $einstellung): ReminderPreference
    {
        $einstellung->forceFill([
            'is_active' => false,
            'q1_enabled' => false,
            'q2_enabled' => false,
            'q3_enabled' => false,
            'december_enabled' => false,
            'deactivated_at' => now(),
            'reactivated_at' => null,
        ])->save();

        $this->protokolliere(self::AUDIT_ABMELDUNG, $einstellung);

        return $einstellung;
    }

    public function reaktivieren(ReminderPreference $einstellung): ReminderPreference
    {
        $einstellung->forceFill([
            'is_active' => true,
            'q1_enabled' => true,
            'q2_enabled' => true,
            'q3_enabled' => true,
            'december_enabled' => true,
            'deactivated_at' => null,
            'reactivated_at' => now(),
        ])->save();

        // Eine Sperre, die auf einer Abmeldung beruht, wird mit der erneuten
        // Aktivierung aufgehoben. Eine Sperre nach Zustellfehler bleibt, weil
        // die Adresse technisch nicht erreichbar ist.
        $nutzer = $this->nutzer($einstellung);

        if ($nutzer instanceof User) {
            $adresse = (string) $nutzer->getAttribute('email');
            $sperre = $this->sperrliste->find($adresse);

            if ($sperre instanceof EmailSuppression
                && $sperre->getAttribute('reason') === EmailSuppressionReason::ABMELDUNG) {
                $this->sperrliste->release($adresse);
            }
        }

        $this->protokolliere(self::AUDIT_REAKTIVIERUNG, $einstellung);

        return $einstellung;
    }

    /**
     * Bezeichnung des betroffenen Objekts, sonst null fuer die globale
     * Einstellung.
     */
    public function objektbezeichnung(ReminderPreference $einstellung): ?string
    {
        $objekt = $einstellung->property;

        if (! $objekt instanceof Property) {
            return null;
        }

        $label = $objekt->getAttribute('label');

        return is_string($label) && $label !== '' ? $label : null;
    }

    private function nutzer(ReminderPreference $einstellung): ?User
    {
        $nutzerId = $einstellung->getAttribute('user_id');

        if (! is_string($nutzerId) || $nutzerId === '') {
            return null;
        }

        /** @var User|null $nutzer */
        $nutzer = User::withTrashed()->whereKey($nutzerId)->first();

        return $nutzer;
    }

    private function protokolliere(string $aktion, ReminderPreference $einstellung): void
    {
        $organisation = $einstellung->getAttribute('organization_id');
        $objektId = $einstellung->getAttribute('property_id');

        $this->audit->record(
            action: $aktion,
            subject: $einstellung,
            actor: $this->nutzer($einstellung),
            organization: is_string($organisation) ? $organisation : null,
            metadata: [
                'geltungsbereich' => is_string($objektId) ? 'objekt' : 'global',
            ],
        );
    }
}
