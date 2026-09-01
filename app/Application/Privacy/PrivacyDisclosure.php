<?php

declare(strict_types=1);

namespace App\Application\Privacy;

/**
 * Auskunftstexte zur Speicherung, an einer Stelle gepflegt.
 *
 * Die Texte werden in der Oberfläche und zusätzlich in der lesbaren Übersicht
 * des Datenexports verwendet. Sie beschreiben die Praxis der Anwendung und
 * stellen keine Rechtsberatung dar.
 *
 * Grundlage: Masterprompt Abschnitt 6.4 und 19, ARCHITECTURE.md Abschnitt 5.2.
 * Es wird ausdrücklich nicht behauptet, eine Datei ließe sich auf gemeinsam
 * genutztem oder SSD-basiertem Storage forensisch überschreiben. Verbindlich
 * sind logische Löschung, Ausschluss aus Backups, kurze Aufbewahrung im
 * Kurzzeitbereich und ein dokumentierter Löschstatus.
 */
final class PrivacyDisclosure
{
    /**
     * Was dauerhaft gespeichert wird.
     *
     * @return list<string>
     */
    public static function storedPermanently(): array
    {
        return [
            'Ihre Kontoangaben: Name, E-Mail-Adresse, Rechnungsanschrift und Einstellungen.',
            'Ihre Stammdaten: Objekte, Einheiten, Mietverhältnisse, Belegungs- und Leerstandszeiträume.',
            'Strukturierte Auslesedaten Ihrer Unterlagen: Dokumenttyp, neutrale Quellenbezeichnung, '
                .'Beträge in Cent, Zeiträume, Kostenart, Seite, Feldbezeichnung, kurzer '
                .'Fundstellenausschnitt, Konfidenz sowie Angaben zu Provider, Modell und Promptversion.',
            'Einen schlüsselgebundenen Fingerabdruck je Datei, ausschließlich zur Dublettenerkennung.',
            'Ihre Bestätigungen, Korrekturen und Prüfstände.',
            'Die Berechnungsstände Ihrer Abrechnungsläufe, damit eine Abrechnung nachvollziehbar bleibt.',
            'Die erzeugten Abrechnungs-PDFs.',
            'Die Rechnungen der Hausverwaltung Müller GmbH, solange handels- und steuerrechtliche '
                .'Aufbewahrungspflichten bestehen.',
            'Ein datensparsames Revisionsprotokoll mit gekürzter IP-Adresse und gehashtem Browserkennzeichen.',
        ];
    }

    /**
     * Was ausdrücklich nicht dauerhaft gespeichert wird.
     *
     * @return list<string>
     */
    public static function neverStoredPermanently(): array
    {
        return [
            'Ihre Originaldateien, also PDFs, Fotos, Office- und ZIP-Dateien.',
            'Die Originaldateinamen.',
            'Vollständige OCR-Texte und vollständige Text-Layer aus PDF-Dateien.',
            'Seitenbilder und Vorschaubilder Ihrer Quelldokumente.',
            'Rohe Anfragen und rohe Antworten des KI-Providers sowie Dateiinhalte in kodierter Form.',
            'EXIF-Daten und andere nicht benötigte technische Metadaten.',
            'Temporäre Datei-Kennungen des KI-Providers nach Abschluss der Verarbeitung.',
        ];
    }

    /**
     * Hinweis auf die eigene Aufbewahrungspflicht des Nutzers.
     */
    public static function ownRecordsNotice(): string
    {
        return 'Ihre Originaldateien werden nur zur Auswertung kurzfristig verarbeitet und anschließend '
            .'automatisch gelöscht. Bitte bewahren Sie Ihre Originalrechnungen, Bescheide und Mietverträge '
            .'selbst auf und halten Sie sie für eine mögliche Belegeinsicht Ihrer Mieter bereit. Die '
            .'Anwendung bietet bewusst keine Möglichkeit, Originalbelege dauerhaft im Konto zu archivieren.';
    }

    /**
     * Was bei der endgültigen Kontolöschung gelöscht wird.
     *
     * @return list<string>
     */
    public static function deletedOnAccountDeletion(): array
    {
        return [
            'Ihr Nutzerkonto mit Name, E-Mail-Adresse und Anmeldedaten.',
            'Ihre Objekte, Einheiten, Mietverhältnisse und Zeiträume.',
            'Ihre Zähler und Zählerstände.',
            'Ihre Abrechnungsläufe mit allen strukturierten Auslesedaten, Kostenpositionen, '
                .'Verteilerschlüsseln, Vorauszahlungen und Prüfhinweisen.',
            'Ihre Berechnungsstände und Mieterabrechnungen.',
            'Die erzeugten Abrechnungs-PDFs und Vorschauen einschließlich der zugehörigen Dateien in der Ablage.',
            'Ihre Erinnerungseinstellungen und die protokollierten Nachrichten an Sie.',
        ];
    }

    /**
     * Was aus Aufbewahrungsgründen erhalten bleibt.
     *
     * @return list<string>
     */
    public static function retainedOnAccountDeletion(): array
    {
        return [
            'Die Rechnungen der Hausverwaltung Müller GmbH über die von Ihnen bezogenen Leistungen. '
                .'Sie sind handels- und steuerrechtlich aufzubewahren und werden von Ihrem gelöschten '
                .'Konto entkoppelt. Erhalten bleiben nur die für die Aufbewahrung erforderlichen Angaben: '
                .'Rechnungsnummer, Datum, Leistungsdatum, Rechnungsanschrift, Beträge und Steuersatz.',
            'Der Löschnachweis über Ihre Quelldaten, ohne Dateiinhalte und ohne Dateinamen.',
            'Ein datensparsamer Eintrag im Revisionsprotokoll über Antrag, Rücknahme und Ausführung der '
                .'Löschung, ohne Ihre Kontaktdaten.',
        ];
    }

    /**
     * Verfahrenshinweis zum Löschantrag.
     */
    public static function deletionProcessNotice(int $graceDays): string
    {
        return sprintf(
            'Nach Ihrem Antrag läuft eine Frist von %d Tagen. Innerhalb dieser Frist können Sie den '
            .'Antrag jederzeit ohne Angabe von Gründen zurücknehmen. Ihr Konto bleibt bis zum Ablauf der '
            .'Frist nutzbar. Nach Ablauf der Frist wird die Löschung durch einen geplanten Lauf '
            .'ausgeführt und kann nicht mehr zurückgenommen werden. Bitte fordern Sie vorher Ihren '
            .'Datenexport an, wenn Sie Ihre Daten behalten möchten.',
            $graceDays,
        );
    }
}
