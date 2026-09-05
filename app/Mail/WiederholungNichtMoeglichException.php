<?php

declare(strict_types=1);

namespace App\Mail;

use RuntimeException;

/**
 * Eine Nachricht kann nicht erneut versendet werden: falscher Status,
 * Hoechstzahl der Versuche erreicht, Wiederholungsfenster abgelaufen oder
 * kein Wiederholungspuffer vorhanden. Die Meldung ist fuer die Anzeige im
 * Adminbereich bestimmt.
 */
final class WiederholungNichtMoeglichException extends RuntimeException {}
