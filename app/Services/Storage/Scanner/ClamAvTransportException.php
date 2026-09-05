<?php

declare(strict_types=1);

namespace App\Services\Storage\Scanner;

use RuntimeException;

/**
 * Technischer Fehler der Verbindung zum ClamAV-Daemon.
 *
 * DATENSCHUTZ: Die Meldung beschreibt ausschliesslich den Verbindungsfehler.
 * Sie enthaelt keinen Dateiinhalt und keinen Dateinamen.
 */
class ClamAvTransportException extends RuntimeException {}
