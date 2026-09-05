<?php

declare(strict_types=1);

use App\Providers\AiServiceProvider;
use App\Providers\ApplicationBindingsProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    // Verdrahtet die fachlichen Nahtstellen: Aufbereitung des gesperrten
    // Berechnungsstandes fuer die Final-PDFs und Versand der
    // Bestaetigungsmails nach der Finalisierung.
    ApplicationBindingsProvider::class,
    // Verdrahtet die KI-Schicht mit der Dokumentpipeline. Ohne diesen Provider
    // laeuft die Pipeline bis Dead Letter und loescht die Quelldaten sofort;
    // Originaldateien bleiben also auch ohne KI-Anbindung niemals liegen.
    AiServiceProvider::class,
];
