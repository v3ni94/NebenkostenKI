<?php

declare(strict_types=1);

namespace App\Application\Privacy;

use App\Application\Account\AuditRecorder;
use App\Application\Privacy\Dto\AccountDeletionState;
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
 */
final class AccountDeletionWorkflow
{
    public const ACTION_REQUESTED = 'privacy.deletion.requested';

    public const ACTION_WITHDRAWN = 'privacy.deletion.withdrawn';

    public const ACTION_EXECUTED = 'privacy.deletion.executed';

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

    public function __construct(private readonly AuditRecorder $audit) {}

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

        return AccountDeletionState::pending($jetzt, $faellig, $frist);
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
