<?php

declare(strict_types=1);

namespace App\Services\Ai\Dto;

/**
 * Aufrufkontext eines KI-Aufrufs.
 *
 * Der Kontext enthaelt ausschliesslich technische Steuerungsdaten. Es werden
 * keine personenbezogenen Angaben mitgefuehrt. Die Nutzerreferenz ist eine
 * undurchsichtige Kennung, zum Beispiel eine ULID, niemals eine E-Mail-
 * Adresse oder ein Name.
 *
 * Das Tagesbudget wird als Parameter uebergeben. Die KI-Schicht greift nicht
 * auf die Datenbank zu.
 */
final class AiRequestContext
{
    /**
     * @param  string  $correlationId  Korrelations-ID fuer Protokolle.
     * @param  string|null  $userReference  Undurchsichtige Nutzerkennung, ohne personenbezogene Angaben.
     * @param  int  $dailySpentMilliCent  Bereits verbrauchtes Tagesbudget in Tausendstel-Cent.
     * @param  int|null  $estimatedInputTokens  Schaetzung der Eingabetoken fuer die Vorabpruefung des Budgets.
     * @param  bool  $allowProviderFileUpload  Erlaubt den Umweg ueber die Files-API des Providers, falls die
     *                                         Datei nicht direkt in den Verarbeitungsrequest passt.
     */
    public function __construct(
        public readonly string $correlationId,
        public readonly ?string $userReference = null,
        public readonly int $dailySpentMilliCent = 0,
        public readonly ?int $estimatedInputTokens = null,
        public readonly bool $allowProviderFileUpload = true,
    ) {}

    public static function forCorrelation(string $correlationId): self
    {
        return new self($correlationId);
    }

    public function withDailySpentMilliCent(int $milliCent): self
    {
        return new self(
            $this->correlationId,
            $this->userReference,
            $milliCent,
            $this->estimatedInputTokens,
            $this->allowProviderFileUpload,
        );
    }
}
