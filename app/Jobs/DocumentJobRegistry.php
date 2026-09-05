<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Queue\JobHandlerRegistry;

/**
 * Baut die Registry der Teiljobs des Dokumentlebenszyklus.
 *
 * Bewusst als eigene Klasse und nicht als Bindung in einem ServiceProvider:
 * die Queue-Schicht bleibt dadurch frei von fachlichen Abhaengigkeiten und der
 * Testlauf kann eine eigene Registry mit Testhandlern verwenden.
 */
final class DocumentJobRegistry
{
    public function make(): JobHandlerRegistry
    {
        $registry = new JobHandlerRegistry;

        foreach (DocumentJobType::cases() as $type) {
            $registry->register($type->value, $type->handlerClass());
        }

        return $registry;
    }
}
