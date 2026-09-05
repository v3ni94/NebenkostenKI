<?php

declare(strict_types=1);

namespace App\Application\Payment;

/**
 * Versionierte Textfassungen der Zustimmungen im Checkout (Abschnitt 2.3).
 *
 * VOR LIVEGANG DURCH RECHTSANWALT PRÜFEN UND FREIGEBEN
 *
 * Die Texte sind ausdrueckliche Platzhalter. Sie stehen hier und nicht in der
 * Blade-Vorlage, damit genau der angezeigte Text protokolliert und gehasht
 * wird. Wird der Text geaendert, ist die Version zu erhoehen; alte
 * Zustimmungen bleiben dadurch mit ihrer eigenen Fassung belegbar
 * (Abschnitt 2.3, Datenmodell legal_acceptances).
 */
final class CheckoutTexts
{
    /**
     * Fassung der hier hinterlegten Texte. Bei jeder Textaenderung erhoehen.
     */
    public const string VERSION = '2026-01-ENTWURF';

    /**
     * Gesonderte, nicht vorangekreuzte Zustimmung zur sofortigen Ausfuehrung
     * des Vertrags und zum moeglichen Erloeschen des Widerrufsrechts.
     */
    public const string IMMEDIATE_PERFORMANCE = 'Ich verlange ausdrücklich, dass die Hausverwaltung Müller GmbH '
        .'mit der Erstellung der Final-Abrechnungen sofort nach meiner Zahlung beginnt. Mir ist bekannt, dass ich '
        .'mein Widerrufsrecht mit der vollständigen Vertragserfüllung verliere. '
        .'[Platzhaltertext, vor Livegang anwaltlich zu prüfen und freizugeben]';

    /**
     * Bestaetigung der Vertragsgrundlagen.
     */
    public const string TERMS = 'Ich habe die Allgemeinen Geschäftsbedingungen, die Datenschutzerklärung und die '
        .'Widerrufsbelehrung gelesen und stimme ihnen zu. '
        .'[Platzhaltertext, vor Livegang anwaltlich zu prüfen und freizugeben]';

    /**
     * SHA-256 der genau angezeigten Textfassung. Damit ist spaeter belegbar,
     * welchem Wortlaut zugestimmt wurde.
     */
    public static function hash(string $text): string
    {
        return hash('sha256', $text);
    }
}
