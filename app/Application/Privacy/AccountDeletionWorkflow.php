<?php

declare(strict_types=1);

namespace App\Application\Privacy;

use App\Application\Account\AuditRecorder;
use App\Application\Privacy\Dto\AccountDeletionState;
use App\Mail\LoeschantragEingegangenMail;
use App\Mail\LoeschantragErinnerungMail;
use App\Mail\MailDispatcher;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Konto-Löschworkflow mit dokumentierter Frist (Masterprompt 19).
 *
 * ABLAUF, verbindlich:
 *
 *   1. Der Nutzer beantragt die Löschung. Der Antrag setzt eine Frist.
 *   2. Innerhalb der Frist kann er den Antrag jederzeit zurücknehmen. Das Konto
 *      bleibt bis zum Ablauf uneingeschränkt nutzbar.
 *   3. Nach Ablauf der Frist führt ein geplanter Lauf die endgültige Löschung
 *      aus (siehe ExecuteAccountDeletion).
 *
 * ZUSTANDSHALTUNG: Der Antrag wird nicht in einer eigenen Spalte gehalten,
 * sondern als Ereignis im Revisionsprotokoll geführt und daraus abgeleitet.
 * Grund: Antrag, Rücknahme und Ausführung sind ohnehin protokollpflichtig, und
 * ein abgeleiteter Zustand kann nicht von seinem Nachweis abweichen. Der
 * jüngste der drei Einträge bestimmt den Zustand.
 *
 * FRIST: Die Frist ist konfigurierbar über
 * config('smartabrechnen.retention.account_deletion_grace_days'). Ist der Wert
 * nicht gesetzt, gilt DEFAULT_GRACE_DAYS. Vorgesehene Umgebungsvariable:
 * ACCOUNT_DELETION_GRACE_DAYS. Die Frist ist eine betriebliche Festlegung und
 * ersetzt keine rechtliche Prüfung der Aufbewahrungspflichten.
 *
 * BENACHRICHTIGUNG: Der Antrag ist eine kritische Kontonachricht. Beim Antrag
 * geht eine Bestätigungsmail mit Fälligkeit und Rücknahmeweg an den Inhaber,
 * einige Tage vor der Ausführung eine Erinnerung. Beide Nachrichten werden
 * auch an eine gesperrte Adresse zugestellt, weil der Inhaber sonst eine
 * Löschung übersehen könnte, die ein Dritter über eine übernommene Sitzung
 * angestoßen hat. Ein Zustellfehler wird vom MailDispatcher protokolliert und
 * verhindert den Antrag selbst nicht.
 */
final class AccountDeletionWorkflow
{
    public const ACTION_REQUESTED = 'privacy.deletion.requested';

    public const ACTION_WITHDRAWN = 'privacy.deletion.withdrawn';

    public const ACTION_EXECUTED = 'privacy.deletion.executed';

    public const ACTION_REMINDED = 'privacy.deletion.reminded';

    /**
     * Vorlauf der Erinnerung in Tagen vor der Fälligkeit.
     */
    public const REMINDER_DAYS_BEFORE = 5;

    /**
     * Frist in Tagen, wenn nichts konfiguriert ist.
     */
    public const DEFAULT_GRACE_DAYS = 30;

    /**
     * Untere und obere Schranke, damit eine fehlerhafte Konfiguration die Frist
     * nicht auf null Tage verkürzt oder unbegrenzt verlängert.
     */
    public const MIN_GRACE_DAYS = 7;

    public const MAX_GRACE_DAYS = 90;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly MailDispatcher $mails,
    ) {}

    public function graceDays(): int
    {
        $wert = config('smartabrechnen.retention.account_deletion_grace_days');

        if (! is_numeric($wert)) {
            return self::DEFAULT_GRACE_DAYS;
        }

        return max(self::MIN_GRACE_DAYS, min(self::MAX_GRACE_DAYS, (int) $wert));
    }

    /**
     * Aktueller Zustand des Antrags.
     */
    public function state(User $user): AccountDeletionState
    {
        $frist = $this->graceDays();
        $letzter = $this->latestEvent($user);

        if (! $letzter instanceof AuditLog || $letzter->getAttribute('action') !== self::ACTION_REQUESTED) {
            return AccountDeletionState::none($frist);
        }

        $beantragt = $letzter->getAttribute('occurred_at');

        if (! $beantragt instanceof Carbon) {
            return AccountDeletionState::none($frist);
        }

        return AccountDeletionState::pending(
            $beantragt->copy(),
            $this->dueAtFrom($letzter, $beantragt, $frist),
            $frist,
        );
    }

    /**
     * Antrag stellen. Ein bereits laufender Antrag wird nicht verdoppelt.
     */
    public function request(User $user, Organization $organization): AccountDeletionState
    {
        $vorhanden = $this->state($user);

        if ($vorhanden->pending) {
            return $vorhanden;
        }

        $frist = $this->graceDays();
        $jetzt = Carbon::now();
        $faellig = $jetzt->copy()->addDays($frist);

        $this->audit->record(
            action: self::ACTION_REQUESTED,
            subject: $user,
            actor: $user,
            organization: $organization,
            metadata: [
                'grace_days' => $frist,
                'due_at' => $faellig->toIso8601String(),
            ],
            reason: 'Löschantrag des Nutzers nach Artikel 17 DSGVO',
        );

        $zustand = AccountDeletionState::pending($jetzt, $faellig, $frist);

        $this->mails->send(
            new LoeschantragEingegangenMail(
                $this->anrede($user),
                $faellig,
                $frist,
                route('portal.datenschutz.show'),
            ),
            (string) $user->getAttribute('email'),
            $user,
            (string) $organization->getKey(),
        );

        return $zustand;
    }

    /**
     * Erinnert alle Antragsteller, deren Löschung in höchstens
     * REMINDER_DAYS_BEFORE Tagen fällig ist, genau einmal je Antrag.
     *
     * Der Lauf ist idempotent: Eine bereits versendete Erinnerung wird über
     * das Protokollereignis erkannt, das nach dem Antrag liegt.
     *
     * @return int Anzahl versendeter Erinnerungen
     */
    public function remindDue(int $limit = 50): int
    {
        /** @var list<string> $kandidaten */
        $kandidaten = AuditLog::query()
            ->where('action', self::ACTION_REQUESTED)
            ->whereNotNull('actor_user_id')
            ->orderBy('occurred_at')
            ->pluck('actor_user_id')
            ->unique()
            ->values()
            ->all();

        $versendet = 0;

        foreach ($kandidaten as $userId) {
            if ($versendet >= max(1, $limit)) {
                break;
            }

            /** @var User|null $nutzer */
            $nutzer = User::query()->whereKey($userId)->first();

            if (! $nutzer instanceof User) {
                continue;
            }

            $zustand = $this->state($nutzer);

            if (! $zustand->pending || $zustand->isDue() || $zustand->requestedAt === null || $zustand->dueAt === null) {
                continue;
            }

            if ($zustand->dueAt->copy()->subDays(self::REMINDER_DAYS_BEFORE)->isFuture()) {
                continue;
            }

            if ($this->reminderSentSince($nutzer, $zustand->requestedAt)) {
                continue;
            }

            $organisationId = $nutzer->organizationIds()[0] ?? null;

            $this->mails->send(
                new LoeschantragErinnerungMail(
                    $this->anrede($nutzer),
                    $zustand->dueAt,
                    max(1, $zustand->remainingDays()),
                    route('portal.datenschutz.show'),
                ),
                (string) $nutzer->getAttribute('email'),
                $nutzer,
                $organisationId,
            );

            $this->audit->record(
                action: self::ACTION_REMINDED,
                subject: $nutzer,
                actor: $nutzer,
                organization: $organisationId,
                metadata: ['due_at' => $zustand->dueAt->toIso8601String()],
                reason: 'Erinnerung vor der endgültigen Kontolöschung',
            );

            $versendet++;
        }

        return $versendet;
    }

    /**
     * Rücknahme innerhalb der Frist. Nach Ablauf ist keine Rücknahme mehr
     * möglich, weil die Ausführung dann bereits freigegeben ist.
     */
    public function withdraw(User $user, Organization $organization): bool
    {
        $zustand = $this->state($user);

        if (! $zustand->pending || $zustand->isDue()) {
            return false;
        }

        $this->audit->record(
            action: self::ACTION_WITHDRAWN,
            subject: $user,
            actor: $user,
            organization: $organization,
            metadata: [
                'requested_at' => $zustand->requestedAt?->toIso8601String(),
            ],
            reason: 'Rücknahme des Löschantrags innerhalb der Frist',
        );

        return true;
    }

    /**
     * Nutzer, deren Frist abgelaufen ist und deren Antrag noch offen steht.
     *
     * @return list<User>
     */
    public function due(int $limit = 50): array
    {
        /** @var list<string> $kandidaten */
        $kandidaten = AuditLog::query()
            ->where('action', self::ACTION_REQUESTED)
            ->whereNotNull('actor_user_id')
            ->orderBy('occurred_at')
            ->pluck('actor_user_id')
            ->unique()
            ->values()
            ->all();

        $faellig = [];

        foreach ($kandidaten as $userId) {
            if (count($faellig) >= max(1, $limit)) {
                break;
            }

            /** @var User|null $nutzer */
            $nutzer = User::query()->withTrashed()->whereKey($userId)->first();

            if (! $nutzer instanceof User) {
                continue;
            }

            if ($this->state($nutzer)->isDue()) {
                $faellig[] = $nutzer;
            }
        }

        return $faellig;
    }

    private function reminderSentSince(User $user, Carbon $requestedAt): bool
    {
        return AuditLog::query()
            ->where('actor_user_id', $user->getKey())
            ->where('action', self::ACTION_REMINDED)
            ->where('occurred_at', '>=', $requestedAt)
            ->exists();
    }

    private function anrede(User $user): string
    {
        $name = $user->getAttribute('name');

        return is_string($name) && trim($name) !== ''
            ? 'Guten Tag '.trim($name).','
            : 'Guten Tag,';
    }

    /**
     * Jüngstes Ereignis des Verfahrens für diesen Nutzer.
     */
    private function latestEvent(User $user): ?AuditLog
    {
        /** @var AuditLog|null $eintrag */
        $eintrag = AuditLog::query()
            ->where('actor_user_id', $user->getKey())
            ->whereIn('action', [self::ACTION_REQUESTED, self::ACTION_WITHDRAWN, self::ACTION_EXECUTED])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        return $eintrag;
    }

    /**
     * Fälligkeit aus dem Antrag. Der im Antrag protokollierte Termin hat
     * Vorrang, damit eine spätere Änderung der Konfiguration einen laufenden
     * Antrag nicht unbemerkt vorzieht oder verschiebt.
     */
    private function dueAtFrom(AuditLog $eintrag, Carbon $beantragt, int $frist): Carbon
    {
        $metadata = $eintrag->getAttribute('metadata');

        if (is_array($metadata) && isset($metadata['due_at']) && is_string($metadata['due_at'])) {
            return Carbon::parse($metadata['due_at']);
        }

        return $beantragt->copy()->addDays($frist);
    }
}
