<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Tests\TestCase;

/**
 * Prueft die Zeitplaneintraege aus routes/console.php.
 *
 * BETRIEBSMODELL (ADR-006): Ein IONOS-Cronjob ruft schedule:run auf. Der
 * Zeitplan startet den Queue-Lauf, den TTL-Cleanup und die Wiederholung
 * fehlgeschlagener Loeschungen. Es gibt keinen dauerhaften Worker und kein
 * Redis.
 */
class SchedulerRegistrationTest extends TestCase
{
    public function test_alle_drei_zeitplaneintraege_sind_registriert(): void
    {
        $befehle = $this->geplanteBefehle();

        foreach ([
            'smartabrechnen:queue-slice',
            'smartabrechnen:cleanup-temporary-uploads',
            'smartabrechnen:retry-failed-deletions',
            'smartabrechnen:retry-finalization',
        ] as $befehl) {
            $this->assertTrue(
                $this->enthaelt($befehle, $befehl),
                'Der Zeitplan muss '.$befehl.' enthalten.'
            );
        }
    }

    public function test_die_eintraege_laufen_ohne_ueberlappung(): void
    {
        foreach ($this->geplanteEreignisse() as $ereignis) {
            if (! str_contains($ereignis->command ?? '', 'smartabrechnen:')) {
                continue;
            }

            $this->assertTrue(
                $ereignis->withoutOverlapping,
                'Ein haengender Lauf darf den naechsten nicht blockieren: '.$ereignis->command
            );
        }
    }

    public function test_der_queue_lauf_ist_zeitlich_begrenzt(): void
    {
        $befehle = $this->geplanteBefehle();

        $treffer = array_values(array_filter(
            $befehle,
            static fn (string $befehl): bool => str_contains($befehl, 'smartabrechnen:queue-slice')
        ));

        $this->assertNotSame([], $treffer);
        $this->assertStringContainsString('--max-time=45', $treffer[0]);
    }

    public function test_das_cleanup_intervall_folgt_der_konfiguration(): void
    {
        $ereignisse = array_values(array_filter(
            $this->geplanteEreignisse(),
            static fn (Event $ereignis): bool => str_contains(
                $ereignis->command ?? '',
                'smartabrechnen:cleanup-temporary-uploads'
            )
        ));

        $this->assertNotSame([], $ereignisse);
        $this->assertMatchesRegularExpression('#^\*/\d+ \* \* \* \*$#', $ereignisse[0]->expression);
    }

    public function test_die_konsolenbefehle_sind_registriert(): void
    {
        $befehle = array_keys($this->app->make(Kernel::class)->all());

        $this->assertContains('smartabrechnen:queue-slice', $befehle);
        $this->assertContains('smartabrechnen:cleanup-temporary-uploads', $befehle);
        $this->assertContains('smartabrechnen:retry-failed-deletions', $befehle);
    }

    /**
     * @return list<Event>
     */
    private function geplanteEreignisse(): array
    {
        return array_values($this->app->make(Schedule::class)->events());
    }

    /**
     * @return list<string>
     */
    private function geplanteBefehle(): array
    {
        return array_values(array_map(
            static fn (Event $ereignis): string => (string) $ereignis->command,
            $this->geplanteEreignisse()
        ));
    }

    /**
     * @param  list<string>  $befehle
     */
    private function enthaelt(array $befehle, string $gesucht): bool
    {
        foreach ($befehle as $befehl) {
            if (str_contains($befehl, $gesucht)) {
                return true;
            }
        }

        return false;
    }
}
