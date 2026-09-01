<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Enums\AiCallPurpose;
use App\Models\AiCall;
use App\Models\AiPromptVersion;
use App\Services\Ai\AiProviderRouter;
use App\Services\Ai\Dto\AiRequestContext;
use App\Services\Ai\Dto\HealthCheckRequest;
use App\Services\Ai\Dto\HealthCheckResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * KI-Bereich des Adminbereichs (Masterprompt 13.2, 13.8, 20).
 *
 * HEALTHCHECK
 *
 * Der Healthcheck laeuft ausschliesslich ueber die vorhandene
 * Providerabstraktion (AiProviderRouter::healthCheckAll). Er sendet keinen
 * Dokumentinhalt und prueft zwei getrennte Fragen: ist der Provider erreichbar,
 * und ist das konfigurierte Modell dort tatsaechlich verfuegbar. Die
 * Datenschutzfreigabe ist eine dritte, davon unabhaengige Angabe.
 *
 * Im Test wird der Transportadapter der KI-Schicht ersetzt, es entsteht kein
 * echter Providerabruf.
 *
 * KOSTEN
 *
 * Die Kosten stammen ausschliesslich aus ai_calls. Es wird kein Preis
 * geschaetzt und keine Rechnung des Providers nachgebildet. Ein
 * ungewoehnlicher Anstieg wird als Hinweis gemeldet, nicht als Sperre.
 *
 * SECRETS werden nicht ausgegeben. Zu einem API-Key wird nur gemeldet, ob er
 * gesetzt ist.
 */
final class AiOverview
{
    /**
     * Faktor, ab dem ein Kostenanstieg als ungewoehnlich gemeldet wird.
     */
    public const float ANSTIEG_FAKTOR = 2.0;

    /**
     * Untergrenze in Cent, unterhalb der ein Anstieg nicht gemeldet wird. Ohne
     * diese Grenze waere jede Schwankung im Centbereich ein Alarm.
     */
    public const int ANSTIEG_MINDESTBETRAG_CENT = 500;

    public function __construct(private readonly AiProviderRouter $router) {}

    /**
     * Healthcheck je Provider.
     *
     * @return array<string, HealthCheckResult>
     */
    public function healthChecks(bool $analyzeModel = false): array
    {
        $request = new HealthCheckRequest(
            new AiRequestContext('admin-healthcheck-'.Str::lower((string) Str::ulid())),
            $analyzeModel,
        );

        try {
            return $this->router->healthCheckAll($request);
        } catch (Throwable) {
            // Ein Konfigurationsfehler darf den Adminbereich nicht unbenutzbar
            // machen. Die Livegang-Blocker nennen die Ursache ohnehin.
            return [];
        }
    }

    /**
     * Aktive Modelle je Provider, ohne Schluessel.
     *
     * @return list<array{provider: string, modell: string, api_key_gesetzt: bool, erreichbar: bool, modell_verfuegbar: bool, freigegeben: bool, meldung: string, dauer_ms: int}>
     */
    public function providerState(bool $analyzeModel = false): array
    {
        $rows = [];

        foreach ($this->healthChecks($analyzeModel) as $result) {
            $rows[] = [
                'provider' => $result->providerKey,
                'modell' => $result->model,
                'api_key_gesetzt' => $result->apiKeyConfigured,
                'erreichbar' => $result->reachable,
                'modell_verfuegbar' => $result->modelAvailable,
                'freigegeben' => $result->releasedForProduction,
                'meldung' => $result->message,
                'dauer_ms' => $result->durationMs,
            ];
        }

        return $rows;
    }

    public function primaryProvider(): string
    {
        return $this->router->primaryProviderKey()->value;
    }

    public function fallbackProvider(): ?string
    {
        return $this->router->fallbackProviderKey()?->value;
    }

    /**
     * Aktive und vergangene Promptversionen.
     *
     * @return list<array{zweck: string, version: string, aktiv: bool, aktiviert_am: string|null, hash_kurz: string}>
     */
    public function promptVersions(int $limit = 50): array
    {
        /** @var list<AiPromptVersion> $versions */
        $versions = AiPromptVersion::query()
            ->orderBy('purpose')
            ->orderByDesc('is_active')
            ->orderByDesc('version')
            ->limit($limit)
            ->get()
            ->all();

        $rows = [];

        foreach ($versions as $version) {
            $purpose = $version->getAttribute('purpose');
            $activated = $version->getAttribute('activated_at');

            $rows[] = [
                'zweck' => $purpose instanceof AiCallPurpose ? $purpose->label() : (string) $purpose,
                'version' => (string) $version->getAttribute('version'),
                'aktiv' => (bool) $version->getAttribute('is_active'),
                'aktiviert_am' => $activated === null
                    ? null
                    : Carbon::parse((string) $activated)->format('d.m.Y'),
                // Nur der gekuerzte Hash, niemals der Prompttext selbst.
                'hash_kurz' => mb_substr((string) $version->getAttribute('hash'), 0, 12),
            ];
        }

        return $rows;
    }

    /**
     * Kosten und Aufrufzahlen eines Zeitraums.
     *
     * @return array{aufrufe: int, kosten_cent: int, eingabetoken: int, ausgabetoken: int, fehler: int}
     */
    public function periodTotals(Carbon $from, Carbon $to): array
    {
        $query = AiCall::query()
            ->whereBetween('created_at', [$from, $to]);

        return [
            'aufrufe' => (clone $query)->count(),
            'kosten_cent' => (int) (clone $query)->sum('cost_cent'),
            'eingabetoken' => (int) (clone $query)->sum('input_tokens'),
            'ausgabetoken' => (int) (clone $query)->sum('output_tokens'),
            'fehler' => (clone $query)->whereNotNull('error_code')->count(),
        ];
    }

    /**
     * Kosten je Nutzer eines Zeitraums. Zugeordnet wird ueber den
     * Abrechnungslauf, weil ai_calls bewusst keine Nutzerreferenz fuehrt.
     *
     * @return list<array{nutzer_id: string, name: string, email: string, kosten_cent: int, aufrufe: int}>
     */
    public function costsPerUser(Carbon $from, Carbon $to, int $limit = 25): array
    {
        $rows = DB::table('ai_calls')
            ->join('billing_runs', 'billing_runs.id', '=', 'ai_calls.billing_run_id')
            ->join('users', 'users.id', '=', 'billing_runs.created_by_user_id')
            ->whereBetween('ai_calls.created_at', [$from, $to])
            ->groupBy('users.id', 'users.name', 'users.email')
            ->orderByDesc(DB::raw('SUM(ai_calls.cost_cent)'))
            ->limit($limit)
            ->get([
                'users.id as nutzer_id',
                'users.name as name',
                'users.email as email',
                DB::raw('SUM(ai_calls.cost_cent) as kosten_cent'),
                DB::raw('COUNT(ai_calls.id) as aufrufe'),
            ])
            ->all();

        $result = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $data */
            $data = (array) $row;

            $result[] = [
                'nutzer_id' => is_scalar($data['nutzer_id'] ?? null) ? (string) $data['nutzer_id'] : '',
                'name' => is_scalar($data['name'] ?? null) ? (string) $data['name'] : '',
                'email' => is_scalar($data['email'] ?? null) ? (string) $data['email'] : '',
                'kosten_cent' => is_numeric($data['kosten_cent'] ?? null) ? (int) $data['kosten_cent'] : 0,
                'aufrufe' => is_numeric($data['aufrufe'] ?? null) ? (int) $data['aufrufe'] : 0,
            ];
        }

        return $result;
    }

    /**
     * Tageskosten der letzten Tage, aeltester Tag zuerst.
     *
     * @return array<string, int> Datum TT.MM.JJJJ auf Kosten in Cent
     */
    public function dailyCostCent(int $days = 14): array
    {
        $series = [];
        $today = Carbon::now()->startOfDay();

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $day = $today->copy()->subDays($offset);

            $series[$day->format('d.m.Y')] = (int) AiCall::query()
                ->whereBetween('created_at', [$day, $day->copy()->endOfDay()])
                ->sum('cost_cent');
        }

        return $series;
    }

    /**
     * Warnung bei ungewoehnlichem Kostenanstieg.
     *
     * Verglichen werden die Kosten des laufenden Tages mit dem Tagesmittel der
     * sieben Vortage. Die Meldung ist ein Hinweis zur Pruefung, keine Sperre.
     */
    public function costSpikeWarning(): ?string
    {
        $today = Carbon::now()->startOfDay();

        $todayCent = (int) AiCall::query()
            ->whereBetween('created_at', [$today, Carbon::now()])
            ->sum('cost_cent');

        $previousCent = (int) AiCall::query()
            ->whereBetween('created_at', [$today->copy()->subDays(7), $today])
            ->sum('cost_cent');

        $average = $previousCent / 7;

        if ($todayCent < self::ANSTIEG_MINDESTBETRAG_CENT) {
            return null;
        }

        if ($average <= 0.0 || $todayCent < $average * self::ANSTIEG_FAKTOR) {
            return null;
        }

        return sprintf(
            'Die KI-Kosten des laufenden Tages liegen bei %s und damit deutlich über dem Tagesmittel der '
            .'sieben Vortage von %s. Bitte Ursache prüfen.',
            self::formatCent($todayCent),
            self::formatCent((int) round($average)),
        );
    }

    /**
     * Konfigurierte Limits. Es wird kein Schluessel und kein Endpunkt gezeigt.
     *
     * @return array{tageslimit_cent_je_nutzer: int|null, konfidenzschwelle: float, maximale_wiederholungen: int, doppelpruefung_aktiv: bool, fallback_aktiv: bool}
     */
    public function limits(): array
    {
        $limit = config('ai.max_daily_cost_cent_per_user');
        $threshold = config('ai.confidence_review_threshold');
        $retries = config('ai.max_retries');

        return [
            'tageslimit_cent_je_nutzer' => is_numeric($limit) ? (int) $limit : null,
            'konfidenzschwelle' => is_numeric($threshold) ? (float) $threshold : 0.80,
            'maximale_wiederholungen' => is_numeric($retries) ? (int) $retries : 2,
            'doppelpruefung_aktiv' => (bool) config('ai.dual_review_enabled'),
            'fallback_aktiv' => (bool) config('ai.fallback_enabled'),
        ];
    }

    public static function formatCent(int $cent): string
    {
        return number_format($cent / 100, 2, ',', '.').' EUR';
    }
}
