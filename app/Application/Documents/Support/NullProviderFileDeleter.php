<?php

declare(strict_types=1);

namespace App\Application\Documents\Support;

use App\Application\Documents\Contracts\ProviderFileDeleter;
use App\Application\Documents\Dto\ProviderFileDeletionReport;
use App\Enums\AiProvider;

/**
 * Loescher ohne Provideranbindung.
 *
 * Greift, solange keine KI-Schicht gebunden ist und in jedem Test, der den
 * Loeschpfad unabhaengig vom Provider prueft. Die Meldung lautet bewusst
 * "nicht erforderlich" und nicht "erfolgreich": Es wurde keine Providerdatei
 * angelegt, also gibt es auch nichts zu loeschen. Ein Erfolg wuerde einen
 * Loeschvorgang behaupten, der nie stattgefunden hat.
 */
final class NullProviderFileDeleter implements ProviderFileDeleter
{
    public function deleteProviderFile(AiProvider $provider, string $providerFileId): ProviderFileDeletionReport
    {
        return ProviderFileDeletionReport::notRequired();
    }
}
