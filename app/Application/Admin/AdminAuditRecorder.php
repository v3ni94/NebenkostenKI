<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Application\Account\AuditRecorder;
use App\Enums\AdminRole;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Revisionsprotokoll interner Handlungen (Masterprompt 19, ARCHITECTURE.md T10).
 *
 * Ergaenzt App\Application\Account\AuditRecorder um die interne Rolle des
 * Akteurs. Jeder Eintrag enthaelt Akteur, Aktion, Entitaet, Zeitpunkt und eine
 * gekuerzte IP. Die Kuerzung uebernimmt unveraendert
 * AuditRecorder::truncate(), damit im gesamten Projekt genau eine Regel gilt.
 *
 * DATENSCHUTZ: In metadata gehoeren ausschliesslich technische Kennzahlen und
 * Referenz-IDs. Keine Secrets, keine Dokumentinhalte, keine vollstaendige IP,
 * kein User-Agent im Klartext.
 */
final class AdminAuditRecorder
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public function record(
        string $action,
        User $actor,
        ?Model $subject = null,
        Organization|string|null $organization = null,
        array $metadata = [],
        ?string $reason = null,
    ): AuditLog {
        $organizationId = $organization instanceof Organization
            ? $organization->getKey()
            : $organization;

        if ($organizationId === null && $subject !== null) {
            $value = $subject->getAttribute('organization_id');
            $organizationId = is_string($value) && $value !== '' ? $value : null;
        }

        $subjectId = $subject?->getKey();

        /** @var AuditLog $entry */
        $entry = AuditLog::query()->create([
            'organization_id' => $organizationId,
            'actor_user_id' => $actor->getKey(),
            'actor_admin_role' => $this->highestRole($actor)?->value,
            'action' => $action,
            'subject_type' => $subject === null ? null : $subject::class,
            'subject_id' => is_string($subjectId) ? $subjectId : null,
            'occurred_at' => now(),
            'ip_truncated' => $this->truncatedIp(),
            'user_agent_hash' => $this->userAgentHash(),
            'metadata' => $metadata === [] ? null : $metadata,
            'reason' => $reason,
        ]);

        return $entry;
    }

    /**
     * Hoechste aktive interne Rolle des Akteurs. Kundenrollen werden niemals
     * herangezogen.
     */
    public function highestRole(User $actor): ?AdminRole
    {
        foreach ([AdminRole::ADMIN, AdminRole::FINANCE, AdminRole::SUPPORT] as $role) {
            if ($actor->hasAdminRole($role)) {
                return $role;
            }
        }

        return null;
    }

    private function truncatedIp(): ?string
    {
        $ip = $this->request->ip();

        if (! is_string($ip) || $ip === '') {
            return null;
        }

        return AuditRecorder::truncate($ip);
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
