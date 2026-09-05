<?php

declare(strict_types=1);

namespace App\Application\Reminder;

/**
 * Ergebnis eines Erinnerungslaufs.
 *
 * Der Bericht enthaelt ausschliesslich Zaehlwerte und Gruende, keine
 * Empfaengeradressen und keine Kundendaten. Er wird auf der Konsole ausgegeben
 * und darf deshalb nichts Personenbezogenes enthalten.
 */
final class ReminderReport
{
    public const GRUND_KEIN_EMPFAENGER = 'kein_empfaenger';

    public const GRUND_KONTO_NICHT_AKTIV = 'konto_nicht_aktiv';

    public const GRUND_NICHT_BESTAETIGT = 'e_mail_nicht_bestaetigt';

    public const GRUND_ABGEMELDET = 'erinnerung_deaktiviert';

    public const GRUND_FINALISIERT = 'jahreslauf_finalisiert';

    public const GRUND_DUBLETTE = 'dublette_im_fenster';

    public const GRUND_ADRESSE_GESPERRT = 'adresse_gesperrt';

    public const GRUND_VERSAND_FEHLGESCHLAGEN = 'versand_fehlgeschlagen';

    public int $geprueft = 0;

    public int $gesendet = 0;

    /**
     * @var array<string, int>
     */
    public array $uebersprungen = [];

    public function __construct(public readonly ?string $fenster = null) {}

    public function zaehleUebersprungen(string $grund): void
    {
        $this->uebersprungen[$grund] = ($this->uebersprungen[$grund] ?? 0) + 1;
    }

    public function anzahlUebersprungen(?string $grund = null): int
    {
        if ($grund !== null) {
            return $this->uebersprungen[$grund] ?? 0;
        }

        return array_sum($this->uebersprungen);
    }

    public function zusammenfassung(): string
    {
        if ($this->fenster === null) {
            return 'Heute ist kein Erinnerungstermin. Es wurde nichts versendet.';
        }

        $teile = [];

        foreach ($this->uebersprungen as $grund => $anzahl) {
            $teile[] = sprintf('%s: %d', $grund, $anzahl);
        }

        return sprintf(
            'Fenster %s, geprüft %d, gesendet %d, übersprungen %d%s',
            $this->fenster,
            $this->geprueft,
            $this->gesendet,
            $this->anzahlUebersprungen(),
            $teile === [] ? '' : ' ('.implode(', ', $teile).')'
        );
    }
}
