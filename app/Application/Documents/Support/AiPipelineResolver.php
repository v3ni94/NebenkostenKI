<?php

declare(strict_types=1);

namespace App\Application\Documents\Support;

use App\Application\Documents\Contracts\DocumentClassifier;
use App\Application\Documents\Contracts\DocumentExtractor;
use Illuminate\Contracts\Container\Container;

/**
 * Bindet Klassifikation und Extraktion erst zur Laufzeit auf.
 *
 * Solange die KI-Schicht nicht gebunden ist, liefern die Methoden null. Der
 * Teiljob meldet dann den Fehlercode KI_SCHICHT_NICHT_VERFUEGBAR und wird mit
 * Backoff wiederholt. Nach dem letzten Versuch geht er in den
 * Dead-Letter-Status; der Loeschpfad entfernt die Quelldaten anschliessend
 * sofort. Der Lebenszyklus bleibt damit auch ohne KI-Anbindung vollstaendig
 * und datenschutzkonform.
 */
final class AiPipelineResolver
{
    public function __construct(private readonly Container $container) {}

    public function classifier(): ?DocumentClassifier
    {
        if (! $this->container->bound(DocumentClassifier::class)) {
            return null;
        }

        $resolved = $this->container->make(DocumentClassifier::class);

        return $resolved instanceof DocumentClassifier ? $resolved : null;
    }

    public function extractor(): ?DocumentExtractor
    {
        if (! $this->container->bound(DocumentExtractor::class)) {
            return null;
        }

        $resolved = $this->container->make(DocumentExtractor::class);

        return $resolved instanceof DocumentExtractor ? $resolved : null;
    }
}
