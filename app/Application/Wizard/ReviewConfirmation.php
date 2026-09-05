<?php

declare(strict_types=1);

namespace App\Application\Wizard;

use App\Application\Account\AuditRecorder;
use App\Enums\LegalDocumentPurpose;
use App\Models\BillingRun;
use App\Models\LegalAcceptance;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Nutzerbestätigung vor dem Checkout (Masterprompt 2.3, Schritt 10).
 *
 * Der Nutzer bestätigt über eine ausdrücklich NICHT vorangekreuzte Checkbox,
 * dass er
 *   - alle Daten und Ergebnisse geprüft hat,
 *   - die Verantwortung als Vermieter übernimmt,
 *   - Preis und Anzahl der Abrechnungen verstanden hat,
 *   - die rechtlichen Pflichttexte akzeptiert.
 *
 * Die Bestätigung wird mit Textversion, Zweck, Zeitpunkt, gekürzter IP und
 * gehashtem User-Agent in legal_acceptances protokolliert. Es werden weder die
 * vollständige IP noch der User-Agent im Klartext gespeichert.
 *
 * Der Text ist als anwaltlich freizugebender Platzhalter gekennzeichnet.
 */
final class ReviewConfirmation
{
    public const string TEXT_VERSION = 'abrechnungsverantwortung-2026-01';

    public const string AUDIT_ACTION = 'billing_run.review_confirmed';

    /**
     * Verbindlicher Bestätigungstext. VOR LIVEGANG DURCH RECHTSANWALT PRÜFEN
     * UND FREIGEBEN.
     */
    public const string TEXT = 'Ich habe alle Daten, Verteilerschlüssel und Ergebnisse geprüft. Ich übernehme als '
        .'Vermieter die Verantwortung für die Betriebskostenabrechnung. Ich habe den Preis und die Anzahl der '
        .'erzeugten Mieterabrechnungen verstanden. Ich akzeptiere die Allgemeinen Geschäftsbedingungen, die '
        .'Datenschutzerklärung und die Widerrufsbelehrung.';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly Request $request,
    ) {}

    /**
     * Gehashter User-Agent. Der Klartext wird niemals gespeichert.
     */
    private function userAgentHash(): ?string
    {
        $agent = $this->request->userAgent();

        return is_string($agent) && $agent !== '' ? hash('sha256', $agent) : null;
    }

    /**
     * Protokolliert die Bestätigung und setzt die Zeitstempel am Lauf.
     */
    public function record(BillingRun $billingRun, User $actor, int $statementCount, int $priceEstimateCent): LegalAcceptance
    {
        $organizationId = $billingRun->getAttribute('organization_id');

        /** @var LegalAcceptance $acceptance */
        $acceptance = DB::transaction(function () use ($billingRun, $actor, $organizationId): LegalAcceptance {
            /** @var LegalAcceptance $eintrag */
            $eintrag = LegalAcceptance::query()->create([
                'user_id' => $actor->getKey(),
                'organization_id' => is_string($organizationId) ? $organizationId : null,
                'billing_run_id' => $billingRun->getKey(),
                'purpose' => LegalDocumentPurpose::ABRECHNUNGSVERANTWORTUNG,
                'document_version' => self::TEXT_VERSION,
                'document_hash' => hash('sha256', self::TEXT),
                'accepted_at' => Carbon::now(),
                'ip_truncated' => $this->audit->truncatedIp(),
                'user_agent_hash' => $this->userAgentHash(),
            ]);

            $billingRun->forceFill([
                'review_confirmed_at' => Carbon::now(),
                'responsibility_confirmed_at' => Carbon::now(),
            ])->save();

            return $eintrag;
        });

        $this->audit->record(
            action: self::AUDIT_ACTION,
            subject: $billingRun,
            actor: $actor,
            organization: is_string($organizationId) ? $organizationId : null,
            metadata: [
                'textversion' => self::TEXT_VERSION,
                'abrechnungen' => $statementCount,
                'schaetzung_cent' => $priceEstimateCent,
            ],
        );

        return $acceptance;
    }

    /**
     * Liegt eine Bestätigung für den aktuellen Stand vor?
     */
    public function isConfirmed(BillingRun $billingRun): bool
    {
        return $billingRun->getAttribute('review_confirmed_at') !== null
            && $billingRun->getAttribute('responsibility_confirmed_at') !== null;
    }

    /**
     * Eine abrechnungsrelevante Änderung entzieht der Bestätigung die
     * Grundlage. Sie wird daher zurückgenommen; der Protokolleintrag bleibt
     * unverändert bestehen.
     */
    public function reset(BillingRun $billingRun): void
    {
        $billingRun->forceFill([
            'review_confirmed_at' => null,
            'responsibility_confirmed_at' => null,
        ])->save();
    }
}
