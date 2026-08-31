<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Basisdaten der Anwendung.
 *
 * Es werden ausschliesslich Stammdaten angelegt, die die Anwendung zum Betrieb
 * benoetigt. Kundendaten und Testkonten werden hier bewusst nicht erzeugt, damit
 * der Seeder auch in der Produktion gefahrlos laufen kann.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            CostCategorySeeder::class,
        ]);
    }
}
