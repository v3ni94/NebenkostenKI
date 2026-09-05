<?php

declare(strict_types=1);

namespace Database\Factories;

/**
 * Frei erfundene deutsche Testdaten.
 *
 * VERBINDLICH: Keine echten Personen, keine echten Anschriften aus dem Bestand,
 * keine echten IBANs und keine echten Aktenzeichen. Alle E-Mail-Domains enden auf
 * .invalid, damit kein Versand an reale Empfaenger moeglich ist.
 */
final class TestData
{
    /** @var list<string> */
    public const FIRST_NAMES = [
        'Annegret', 'Bernhard', 'Cordula', 'Detlef', 'Elke', 'Frank',
        'Gudrun', 'Hendrik', 'Ilona', 'Joachim', 'Katharina', 'Lars',
        'Marlene', 'Norbert', 'Ottilie', 'Reinhard', 'Sieglinde', 'Torben',
    ];

    /** @var list<string> */
    public const LAST_NAMES = [
        'Rehberg', 'Aumueller', 'Wittkamp', 'Bruhnke', 'Steinhauser',
        'Ohlwein', 'Karstaedt', 'Neuberger', 'Lindtner', 'Sprenger',
        'Vollrath', 'Gehringer', 'Petersilie', 'Quandtberg',
    ];

    /** @var list<string> */
    public const STREETS = [
        'Lindenweg', 'Ahornallee', 'Buchenkamp', 'Fasanenstieg', 'Hollerbusch',
        'Kirschgarten', 'Am Muehlbach', 'Erlengrund', 'Nussbaumhof', 'Sonnenacker',
    ];

    /** @var list<string> */
    public const CITIES = [
        'Neustadt am Rothbach', 'Bergkirchen-Sued', 'Talheim an der Auen',
        'Rosendorf', 'Weidenbach', 'Gruenfelde', 'Hochkamp', 'Seeblick am Moor',
    ];

    /** @var list<string> */
    public const SUPPLIERS = [
        'Stadtwerke Rosendorf Anstalt des oeffentlichen Rechts',
        'Hausmeisterservice Bruhnke GmbH',
        'Waermedienst Talheim GmbH',
        'Aufzugtechnik Ohlwein KG',
        'Gartenpflege Weidenbach GbR',
        'Versicherungskontor Gruenfelde AG',
        'Reinigungsdienst Sprenger e. K.',
    ];

    /** @var list<string> */
    public const HEATING_PROVIDERS = [
        'Waermemess Rothbach GmbH',
        'Abrechnungsdienst Hochkamp GmbH',
        'Verbrauchsdaten Seeblick AG',
    ];

    /**
     * Bewusst ungueltige Platzhalter-Bankverbindung.
     */
    public const PLACEHOLDER_IBAN = 'DE99999999999999999999';

    public const PLACEHOLDER_BIC = 'TESTDEFFXXX';

    public static function street(): string
    {
        return self::STREETS[random_int(0, count(self::STREETS) - 1)].' '.random_int(1, 89);
    }

    public static function postalCode(): string
    {
        return (string) random_int(10000, 99999);
    }

    public static function city(): string
    {
        return self::CITIES[random_int(0, count(self::CITIES) - 1)];
    }
}
