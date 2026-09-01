<?php

declare(strict_types=1);

namespace App\Services\Pdf\Support;

/**
 * Corporate Identity der Hausverwaltung Müller GmbH für PDF-Ausgaben
 * (Abschnitt 18).
 *
 * WICHTIG: Diese Klasse wird ausschließlich in der HVM-Rechnung verwendet.
 * Mieterabrechnungen bleiben bewusst neutral und enthalten weder Logo noch
 * Kennlinie noch HVM-Farben.
 *
 * Das HVM-Logo wird nicht erfunden und nicht generiert. Es wird nur
 * eingebunden, wenn public/ci/Logo_HVM.jpg tatsächlich vorliegt; andernfalls
 * erscheint ein eindeutig benannter Textplatzhalter.
 */
final class HvmCorporateIdentity
{
    public const string ORANGE = '#E6A83C';

    public const string ANTHRAZIT = '#87888A';

    public const string MITTELGRAU = '#9C9D9F';

    public const string HELLGRAU = '#D7D8DA';

    public const string TEXTSCHWARZ = '#1A1A1A';

    public const string LOGO_RELATIVE_PATH = 'ci/Logo_HVM.jpg';

    public const string LOGO_PLACEHOLDER = 'Hausverwaltung Müller GmbH [Logo folgt vor Livegang]';

    /**
     * Absoluter Pfad des Logos oder null, solange die Datei nicht vorliegt.
     */
    public static function logoPath(): ?string
    {
        $path = public_path(self::LOGO_RELATIVE_PATH);

        return is_file($path) ? $path : null;
    }

    public static function hasLogo(): bool
    {
        return self::logoPath() !== null;
    }

    /**
     * HVM-Kennlinie als Tabellenzeile: horizontales Band mit den Abschnitten
     * Anthrazit 0 bis 40 Prozent, Mittelgrau 40 bis 60 Prozent, Orange 60 bis
     * 67,5 Prozent und Hellgrau 67,5 bis 100 Prozent. Auf der Rechnung etwa
     * 3 mm hoch.
     *
     * Umsetzung als Tabelle mit Prozentbreiten, weil im PDF-Template weder
     * Flexbox noch Grid noch CSS-Variablen verwendet werden.
     *
     * @return list<array{width: string, color: string}>
     */
    public static function keylineSegments(): array
    {
        return [
            ['width' => '40%', 'color' => self::ANTHRAZIT],
            ['width' => '20%', 'color' => self::MITTELGRAU],
            ['width' => '7.5%', 'color' => self::ORANGE],
            ['width' => '32.5%', 'color' => self::HELLGRAU],
        ];
    }

    public static function keylineHeightMm(): string
    {
        return '3mm';
    }
}
