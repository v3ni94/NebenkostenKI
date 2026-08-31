<?php

declare(strict_types=1);

namespace Tests\Feature\Upload\Concerns;

/**
 * Merkt sich, welche Provider-Datei-IDs zur Loeschung angefordert wurden.
 *
 * Nur fuer Tests. Die Anwendung selbst protokolliert niemals eine
 * Provider-Datei-ID.
 */
final class ProviderLoeschProtokoll
{
    /**
     * @var list<string>
     */
    public static array $aufrufe = [];

    public static function zuruecksetzen(): void
    {
        self::$aufrufe = [];
    }
}
