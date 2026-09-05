<?php

declare(strict_types=1);

namespace App\Services\Queue;

use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * Zuordnung von Jobtypen zu Bearbeitern.
 *
 * Die Registry ist bewusst leer vorbelegt und wird von aussen gefuellt. Damit
 * bleibt die Queue-Schicht frei von fachlichen Abhaengigkeiten und ist im Test
 * mit einem einzelnen Testhandler nutzbar.
 */
final class JobHandlerRegistry
{
    /**
     * @var array<string, class-string<ProcessingJobHandler>>
     */
    private array $handlers = [];

    /**
     * @param  array<string, class-string<ProcessingJobHandler>>  $handlers
     */
    public function __construct(array $handlers = [])
    {
        foreach ($handlers as $jobType => $handler) {
            $this->register($jobType, $handler);
        }
    }

    /**
     * @param  class-string<ProcessingJobHandler>  $handlerClass
     */
    public function register(string $jobType, string $handlerClass): self
    {
        $this->handlers[$jobType] = $handlerClass;

        return $this;
    }

    public function has(string $jobType): bool
    {
        return array_key_exists($jobType, $this->handlers);
    }

    /**
     * @return list<string>
     */
    public function jobTypes(): array
    {
        return array_keys($this->handlers);
    }

    public function resolve(Container $container, string $jobType): ProcessingJobHandler
    {
        $class = $this->handlers[$jobType] ?? null;

        if ($class === null) {
            throw new RuntimeException(sprintf('Fuer den Jobtyp "%s" ist kein Bearbeiter registriert.', $jobType));
        }

        $handler = $container->make($class);

        if (! $handler instanceof ProcessingJobHandler) {
            throw new RuntimeException(sprintf(
                'Der Bearbeiter "%s" implementiert ProcessingJobHandler nicht.',
                $class
            ));
        }

        return $handler;
    }
}
