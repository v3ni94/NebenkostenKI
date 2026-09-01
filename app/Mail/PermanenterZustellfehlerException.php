<?php

declare(strict_types=1);

namespace App\Mail;

use RuntimeException;

/**
 * Dauerhafter Zustellfehler, zum Beispiel unbekannter Empfaenger.
 *
 * Ein dauerhafter Fehler fuehrt zur Sperrung der Adresse und zu einem Hinweis
 * im Konto (Masterprompt 17.2). Ein zeitweiliger Fehler wird nur protokolliert.
 */
final class PermanenterZustellfehlerException extends RuntimeException {}
