<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Schreibt Revisionseintraege.
 *
 * DATENSCHUTZ, verbindlich (Masterprompt 19, ARCHITECTURE.md T6):
 *
 *  - Es wird nur eine gekuerzte IP gespeichert. Bei IPv4 wird das letzte Oktett
 *    genullt, bei IPv6 werden nur die ersten 48 Bit behalten. Damit bleibt der
 *    Eintrag fuer die Missbrauchserkennung brauchbar, ohne eine Person genau zu
 *    adressieren.
 *  - Der User-Agent wird ausschliesslich als Hash gespeichert, niemals im
 *    Klartext.
 *  - In metadata gehoeren nur technische Kennzahlen und Referenzen. Keine
 *    Passwoerter, keine Tokens, keine Dokumentinhalte, keine Betraege aus
 *    Kundendokumenten.
 *
 * Die Klasse liegt in App\Application\Account, weil sie sowohl von den
 * Kontovorgaengen als auch von der Statusmaschine des Abrechnungslaufs genutzt
 * wird und beide Bereiche denselben Datenschutzregeln unterliegen.
 */
class AuditRecorder
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public function record(
        string $action,
        ?Model $subject = null,
        ?User $actor = null,
        Organization|string|null $organization = null,
        array $metadata = [],
        ?string $reason = null,
    ): AuditLog {
        $organizationId = $organization instanceof Organization
            ? $organization->getKey()
            : $organization;

        if ($organizationId === null && $subject !== null) {
            $wert = $subject->getAttribute('organization_id');
            $organizationId = is_string($wert) && $wert !== '' ? $wert : null;
        }

        $subjectId = $subject?->getKey();

        /** @var AuditLog $eintrag */
        $eintrag = AuditLog::query()->create([
            'organization_id' => $organizationId,
            'actor_user_id' => $actor?->getKey(),
            'actor_admin_role' => null,
            'action' => $action,
            'subject_type' => $subject === null ? null : $subject::class,
            'subject_id' => is_string($subjectId) ? $subjectId : null,
            'occurred_at' => now(),
            'ip_truncated' => $this->truncatedIp(),
            'user_agent_hash' => $this->userAgentHash(),
            'metadata' => $metadata === [] ? null : $metadata,
            'reason' => $reason,
        ]);

        return $eintrag;
    }

    /**
     * Gekuerzte IP der aktuellen Anfrage.
     */
    public function truncatedIp(): ?string
    {
        $ip = $this->request->ip();

        if (! is_string($ip) || $ip === '') {
            return null;
        }

        return self::truncate($ip);
    }

    /**
     * Kuerzt eine IP-Adresse datensparsam.
     *
     * IPv4: letztes Oktett auf 0. IPv6: nur die ersten drei Gruppen, also 48
     * Bit, der Rest wird verworfen.
     */
    public static function truncate(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $teile = explode('.', $ip);
            $teile[3] = '0';

            return implode('.', $teile);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $gruppen = explode(':', $ip);
            $behalten = array_slice($gruppen, 0, 3);

            return implode(':', $behalten).'::';
        }

        return null;
    }

    private function userAgentHash(): ?string
    {
        $agent = $this->request->userAgent();

        if (! is_string($agent) || $agent === '') {
            return null;
        }

        return hash('sha256', $agent);
    }
}
