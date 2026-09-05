<?php

declare(strict_types=1);

namespace App\Mail;

use RuntimeException;

/**
 * Der Versand wurde abgebrochen, weil ein unzulaessiger Anhang vorlag.
 *
 * Verbindlich (Masterprompt 16): Finale Mieterabrechnungen werden nicht
 * unverschluesselt als E-Mail-Anhang versendet. Zulaessig ist ausschliesslich
 * die Leistungsrechnung der Hausverwaltung Mueller GmbH.
 */
final class UnzulaessigerAnhangException extends RuntimeException {}
