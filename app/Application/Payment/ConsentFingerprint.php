<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Application\Account\AuditRecorder;
use Illuminate\Http\Request;

/**
 * Datensparsame Nachweisangaben einer Zustimmung (Abschnitt 2.3, 19).
 *
 * Gespeichert werden ausschliesslich eine gekuerzte IP und ein gehashter
 * User-Agent, niemals die vollstaendige Adresse oder der Klartext des
 * User-Agents. Die Kuerzung der IP verwendet bewusst
 * App\Application\Account\AuditRecorder::truncate(), damit Revisionseintrag und
 * Zustimmungsnachweis derselben Regel folgen und nicht auseinanderlaufen.
 */
final class ConsentFingerprint
{
    public function __construct(private readonly Request $request) {}

    public function truncatedIp(): ?string
    {
        $ip = $this->request->ip();

        if (! is_string($ip) || $ip === '') {
            return null;
        }

        return AuditRecorder::truncate($ip);
    }

    public function userAgentHash(): ?string
    {
        $agent = $this->request->userAgent();

        if (! is_string($agent) || $agent === '') {
            return null;
        }

        return hash('sha256', $agent);
    }
}
