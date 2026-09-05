<?php

declare(strict_types=1);

namespace App\Services\Ai\Integration;

use App\Models\AiCall;
use App\Models\BillingRun;
use App\Models\Document;
use App\Services\Ai\DailyCostLimiter;
use App\Services\Ai\Exceptions\DailyCostLimitExceededException;
use Illuminate\Support\Carbon;

/**
 * Ermittelt das bereits verbrauchte Tagesbudget eines Nutzers aus ai_calls
 * (Abschnitt 13.8).
 *
 * ARBEITSTEILUNG: Die KI-Schicht selbst greift bewusst nicht auf die
 * Datenbank zu. DailyCostLimiter prueft nur uebergebene Zahlen. Diese Klasse
 * ist die dafuer vorgesehene Gegenstelle in der Adapterschicht.
 *
 * RECHENWEG, offengelegt:
 *   1. Nutzer des Dokuments = billing_runs.created_by_user_id des Laufs.
 *   2. Summe ai_calls.cost_cent aller Aufrufe zu Abrechnungslaeufen dieses
 *      Nutzers seit Tagesbeginn in der Anwendungszeitzone (config app.timezone,
 *      Standard Europe/Berlin). Der Nutzertag endet damit um Mitternacht
 *      deutscher Zeit und nicht um 01:00 oder 02:00 Uhr.
 *   3. Umrechnung in Tausendstel-Cent durch Multiplikation mit 1.000.
 *
 * DOKUMENTIERTE ANNAHME: ai_calls fuehrt die Kosten in ganzen Cent und je
 * Aufruf aufgerundet. Die Tagessumme liegt dadurch geringfuegig ueber den
 * rechnerischen Kosten. Die Abweichung wirkt zugunsten des Limits, eine
 * Unterschaetzung waere die gefaehrlichere Richtung.
 *
 * Ist fuer ein Modell keine Kalkulationsbasis hinterlegt, bleibt cost_cent
 * null. Ein solcher Aufruf erhoeht das Tagesbudget nicht; der Adminbereich
 * muss die fehlende Basis melden. Ein geratener Preis waere eine stille
 * Annahme.
 */
final class DailyCostLedger
{
    public function __construct(private readonly DailyCostLimiter $limiter) {}

    public function limiter(): DailyCostLimiter
    {
        return $this->limiter;
    }

    /**
     * Bereits verbrauchtes Tagesbudget in Tausendstel-Cent.
     */
    public function spentMilliCentToday(Document $document): int
    {
        $userId = $this->userReference($document);

        if ($userId === null) {
            return 0;
        }

        // Unterabfrage statt Liste, damit auch ein Konto mit vielen
        // Abrechnungslaeufen keine unbegrenzte IN-Bedingung erzeugt.
        $spentCent = (int) AiCall::query()
            ->whereIn(
                'billing_run_id',
                BillingRun::query()->where('created_by_user_id', $userId)->select('id'),
            )
            ->where('created_at', '>=', Carbon::now()->startOfDay())
            ->sum('cost_cent');

        return max(0, $spentCent) * 1000;
    }

    /**
     * Undurchsichtige Nutzerkennung fuer den Aufrufkontext.
     *
     * DATENSCHUTZ: Es wird ausschliesslich die ULID des Nutzers verwendet,
     * niemals Name oder E-Mail-Adresse (siehe AiRequestContext).
     */
    public function userReference(Document $document): ?string
    {
        $userId = BillingRun::query()
            ->whereKey($document->getAttribute('billing_run_id'))
            ->value('created_by_user_id');

        return is_string($userId) && $userId !== '' ? $userId : null;
    }

    /**
     * Bricht ab, wenn vom Tagesbudget nichts mehr uebrig ist.
     *
     * Die feine Vorabpruefung gegen die geschaetzten Kosten des konkreten
     * Aufrufs leisten die HTTP-Provider selbst, sobald sie Modell und
     * Tokenschaetzung kennen. Hier wird nur der Fall abgefangen, dass das
     * Budget bereits vollstaendig ausgeschoepft ist, damit erst gar keine
     * Datei uebertragen wird.
     *
     * @throws DailyCostLimitExceededException
     */
    public function assertBudgetAvailable(int $spentMilliCent): void
    {
        if (! $this->limiter->isEnabled()) {
            return;
        }

        if ($this->limiter->remainingMilliCent($spentMilliCent) > 0) {
            return;
        }

        /** @var int $limitCent */
        $limitCent = $this->limiter->limitCent();

        throw DailyCostLimitExceededException::forBudget($limitCent, max(0, $spentMilliCent), 0);
    }
}
