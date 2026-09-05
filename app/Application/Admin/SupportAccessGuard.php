<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Supportzugriff auf Kundendaten (Masterprompt 19, ARCHITECTURE.md T10).
 *
 * VERBINDLICHE REGELN
 *
 *  1. Objekte, Laeufe und Organisationen sind ausschliesslich zu
 *     Supportzwecken einsehbar. Ohne vorher erfasste Begruendung gibt es
 *     keinen Einblick.
 *  2. Jede Freischaltung erzeugt einen Audit-Eintrag mit Akteur, Aktion,
 *     Entitaet, Zeitpunkt, gekuerzter IP und der Begruendung.
 *  3. Die Freischaltung gilt zeitlich begrenzt und nur fuer genau die
 *     angefragte Entitaet. Ein zweiter Datensatz erfordert eine zweite
 *     Begruendung.
 *  4. Die Freischaltung liegt in der Sitzung des internen Nutzers. Sie wird
 *     nicht dauerhaft gespeichert, damit kein stiller Dauerzugriff entsteht.
 */
final class SupportAccessGuard
{
    /**
     * Gueltigkeitsdauer einer Freischaltung in Minuten.
     */
    public const int GUELTIGKEIT_MINUTEN = 30;

    public const int BEGRUENDUNG_MINDESTLAENGE = 10;

    private const string SESSION_KEY = 'admin.supportzugriff';

    public function __construct(
        private readonly Request $request,
        private readonly AdminAuditRecorder $audit,
    ) {}

    /**
     * Erfasst die Begruendung, protokolliert sie und schaltet den Einblick frei.
     */
    public function grant(User $actor, string $entity, string $id, string $reason): void
    {
        $granted = $this->granted();
        $granted[$this->key($entity, $id)] = Carbon::now()
            ->addMinutes(self::GUELTIGKEIT_MINUTEN)
            ->toIso8601String();

        $this->request->session()->put(self::SESSION_KEY, $granted);

        $this->audit->record(
            action: 'admin.support.access_granted',
            actor: $actor,
            metadata: [
                'entitaet' => $entity,
                'entitaet_id' => $id,
                'gueltig_minuten' => self::GUELTIGKEIT_MINUTEN,
            ],
            reason: $reason,
        );
    }

    /**
     * Protokolliert den tatsaechlichen Einblick in einen Datensatz.
     */
    public function recordView(User $actor, Model $subject, string $reason = 'Supportzugriff mit erfasster Begründung'): void
    {
        $this->audit->record(
            action: 'admin.support.record_viewed',
            actor: $actor,
            subject: $subject,
            reason: $reason,
        );
    }

    public function allows(string $entity, string $id): bool
    {
        $value = $this->granted()[$this->key($entity, $id)] ?? null;

        if (! is_string($value)) {
            return false;
        }

        return Carbon::parse($value)->isFuture();
    }

    /**
     * @return array<string, string>
     */
    private function granted(): array
    {
        $value = $this->request->session()->get(self::SESSION_KEY, []);

        if (! is_array($value)) {
            return [];
        }

        $granted = [];

        foreach ($value as $key => $expires) {
            if (is_string($key) && is_string($expires)) {
                $granted[$key] = $expires;
            }
        }

        return $granted;
    }

    private function key(string $entity, string $id): string
    {
        return $entity.':'.$id;
    }
}
