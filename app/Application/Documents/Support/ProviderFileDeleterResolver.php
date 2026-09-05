<?php

declare(strict_types=1);

namespace App\Application\Documents\Support;

use App\Application\Documents\Contracts\ProviderFileDeleter;
use Illuminate\Contracts\Container\Container;

/**
 * Bindet die Providerloeschung erst zur Laufzeit auf.
 *
 * Die KI-Schicht wird parallel entwickelt. Der Loeschpfad darf davon nicht
 * abhaengen: Ist keine Umsetzung im Container gebunden, greift die
 * Null-Umsetzung. Damit laeuft die Loeschung auch dann vollstaendig, wenn die
 * Provideranbindung fehlt, gesperrt ist oder ausfaellt.
 */
final class ProviderFileDeleterResolver
{
    public function __construct(private readonly Container $container) {}

    public function resolve(): ProviderFileDeleter
    {
        if (! $this->container->bound(ProviderFileDeleter::class)) {
            return new NullProviderFileDeleter;
        }

        $deleter = $this->container->make(ProviderFileDeleter::class);

        return $deleter instanceof ProviderFileDeleter ? $deleter : new NullProviderFileDeleter;
    }
}
