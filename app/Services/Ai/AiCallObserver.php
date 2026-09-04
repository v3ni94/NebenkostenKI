<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Dto\AiCallMetadata;
use App\Services\Ai\Dto\ProviderFileDeletionOutcome;

/**
 * Beobachter eines einzelnen KI-Aufrufs.
 *
 * Die KI-Schicht kennt bewusst keine Persistenz. Was sie ueber den Ablauf
 * eines Aufrufs weiss und was die Application-Schicht festhalten muss, meldet
 * sie ueber diese Schnittstelle, ohne selbst auf die Datenbank zuzugreifen:
 *
 * - vor jedem einzelnen Providerrequest, damit der laufende Teiljob sein
 *   Lease verlaengern kann. Ein Extraktionsaufruf besteht aus bis zu einem
 *   Upload, mehreren Verarbeitungsrequests und einem Loeschaufruf, jeder
 *   mit eigenem Timeout. Ohne Heartbeat kann das Lease waehrend eines
 *   laufenden Aufrufs ablaufen und ein zweiter Lauf dasselbe Dokument
 *   erneut uebertragen.
 * - sobald eine temporaere Providerdatei angelegt wurde, damit ihre ID fuer
 *   die Dauer der Verarbeitung nachverfolgbar ist. Scheitert die Loeschung
 *   oder bricht der Prozess ab, kann die Loeschung nur wiederholt werden,
 *   wenn die ID ausserhalb des Arbeitsspeichers bekannt ist.
 * - sobald die Loeschung der Providerdatei versucht wurde, mit ihrem Ausgang.
 * - wenn ein Aufruf nach bereits gesendeten Requests mit einer Ausnahme
 *   endet, damit der bis dahin entstandene Tokenverbrauch nicht verloren
 *   geht.
 *
 * Alle Methoden erhalten ausschliesslich Metadaten, niemals Dokumentinhalte,
 * Prompts oder Rohantworten.
 */
interface AiCallObserver
{
    /**
     * Wird unmittelbar vor jedem HTTP-Request an den Provider aufgerufen.
     */
    public function beforeProviderRequest(string $providerKey): void;

    /**
     * Eine temporaere Datei wurde beim Provider angelegt. Die ID ist waehrend
     * der Verarbeitung festzuhalten und nach bestaetigter Loeschung zu
     * entfernen.
     */
    public function providerFileCreated(string $providerKey, string $providerFileId): void;

    /**
     * Die Loeschung der temporaeren Providerdatei wurde versucht.
     */
    public function providerFileReleased(string $providerKey, string $providerFileId, ProviderFileDeletionOutcome $outcome): void;

    /**
     * Der Aufruf endet mit einer Ausnahme, nachdem mindestens ein Request an
     * den Provider gesendet wurde. Die Metadaten tragen den bis dahin
     * entstandenen Verbrauch.
     */
    public function providerCallAborted(AiCallMetadata $metadata): void;
}
