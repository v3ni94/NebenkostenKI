<?php

declare(strict_types=1);

use App\Providers\AiServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    // Verdrahtet die KI-Schicht mit der Dokumentpipeline. Ohne diesen Provider
    // laeuft die Pipeline bis Dead Letter und loescht die Quelldaten sofort;
    // Originaldateien bleiben also auch ohne KI-Anbindung niemals liegen.
    AiServiceProvider::class,
];
