<?php

declare(strict_types=1);

namespace App\Domain\Support;

/**
 * Marker-Interface für alle fachlichen Fehler der Domain-Schicht.
 *
 * Jede Domain-Exception implementiert dieses Interface, damit die
 * Anwendungsschicht fachliche Eingabefehler (abfangbar, dem Nutzer erklärbar)
 * eindeutig von technischen Fehlern unterscheiden kann.
 *
 * Wichtig: Prüfergebnisse, die lediglich die Finalisierung blockieren
 * (z. B. eine Heizkosten-Prüfsumme außerhalb der Toleranz), sind KEINE
 * Exceptions, sondern Rückgabewerte
 * (siehe App\Domain\Calculation\Result\CheckFinding).
 */
interface DomainException extends \Throwable {}
