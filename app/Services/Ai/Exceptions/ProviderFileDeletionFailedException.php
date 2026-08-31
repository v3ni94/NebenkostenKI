<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

use App\Services\Ai\Dto\ProviderFileDeletionOutcome;

/**
 * Eine temporaer beim Provider angelegte Datei konnte nicht ueber die
 * Loeschschnittstelle entfernt werden.
 *
 * Der Loeschstatus ist Teil des Ergebnis-DTOs, damit die Application-Schicht
 * ihn regulaer protokollieren kann. Diese Ausnahme steht fuer den Fall zur
 * Verfuegung, in dem der Aufrufer eine bestaetigte Loeschung erzwingt, also
 * ExtractionResult::assertProviderFilesDeleted(). Ein fehlgeschlagener
 * Loeschvorgang ist im Adminbereich als kritischer Datenschutzalarm
 * anzuzeigen und erneut zu bearbeiten.
 *
 * Die Ausnahme fuehrt keine Provider-Datei-ID im Klartext mit, sondern nur
 * den gekuerzten Handle-Hash aus dem Loeschprotokoll.
 */
final class ProviderFileDeletionFailedException extends AiException
{
    /** @var list<string> */
    private array $handleHashes = [];

    /**
     * @param  list<ProviderFileDeletionOutcome>  $outcomes
     */
    public static function forOutcomes(string $providerKey, array $outcomes): self
    {
        $exception = new self(sprintf(
            'Providerdateien bei "%s" konnten nicht vollstaendig geloescht werden.',
            $providerKey,
        ));

        foreach ($outcomes as $outcome) {
            $exception->handleHashes[] = $outcome->providerFileHandleHash;
        }

        return $exception;
    }

    /**
     * @return list<string>
     */
    public function handleHashes(): array
    {
        return $this->handleHashes;
    }
}
