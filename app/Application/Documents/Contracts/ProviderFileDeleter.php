<?php

declare(strict_types=1);

namespace App\Application\Documents\Contracts;

use App\Application\Documents\Dto\ProviderFileDeletionReport;
use App\Enums\AiProvider;

/**
 * Loeschung einer temporaer beim KI-Provider angelegten Datei.
 *
 * Abschnitt 6.3 Schritt 14 verlangt, dass eine beim Provider angelegte Datei
 * unmittelbar nach der Auswertung ueber dessen Loeschschnittstelle entfernt
 * wird. Der Loeschpfad dieser Anwendung darf dafuer nicht von der vollstaendigen
 * KI-Schicht abhaengen: Er muss auch dann laufen, wenn ein Provider gesperrt,
 * nicht konfiguriert oder gerade nicht erreichbar ist.
 *
 * Die Schnittstelle ist deshalb bewusst schmal. Sie kennt weder Prompts noch
 * Schemata noch Kosten, sondern nur Provider, Datei-ID und Ergebnis. Die
 * KI-Schicht setzt sie ueber ihre eigene Providerabstraktion um; ohne Bindung
 * greift NullProviderFileDeleter.
 *
 * DATENSCHUTZ: Die Provider-Datei-ID ist ein Kurzzeitdatum. Sie wird nach
 * Abschluss der Verarbeitung nicht dauerhaft gespeichert (Abschnitt 6.4) und
 * darf weder protokolliert noch in einen Queue-Payload geschrieben werden.
 */
interface ProviderFileDeleter
{
    /**
     * Loescht die Providerdatei. Eine Umsetzung wirft keine Ausnahme, sondern
     * meldet das Ergebnis, damit der lokale Loeschpfad in jedem Fall
     * fortgesetzt wird.
     */
    public function deleteProviderFile(AiProvider $provider, string $providerFileId): ProviderFileDeletionReport;
}
