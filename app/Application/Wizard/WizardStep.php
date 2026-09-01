<?php

declare(strict_types=1);

namespace App\Application\Wizard;

/**
 * Die Schritte des geführten Ablaufs (Masterprompt Abschnitt 9).
 *
 * Der Schritt wird im Abrechnungslauf gespeichert (Spalte wizard_step). Der
 * Nutzer kann jederzeit unterbrechen und ohne Datenverlust fortsetzen; die
 * Zurück-Navigation ist immer erlaubt, weil jeder Schritt seine Daten sofort
 * speichert.
 *
 * Die Schritte 1 bis 6 werden von anderen Bausteinen bereitgestellt und hier
 * ausschließlich verlinkt.
 */
enum WizardStep: int
{
    case KONTO_UND_ZEITRAUM = 1;
    case UPLOAD = 2;
    case ANALYSE = 3;
    case OBJEKT_UND_EINHEITEN = 4;
    case MIETVERHAELTNISSE = 5;
    case KOSTENPRUEFUNG = 6;
    case VORAUSZAHLUNGEN = 7;
    case VERTEILERSCHLUESSEL = 8;
    case PRUEFBERICHT = 9;
    case VORSCHAU = 10;

    public function label(): string
    {
        return match ($this) {
            self::KONTO_UND_ZEITRAUM => 'Konto und Abrechnungszeitraum',
            self::UPLOAD => 'Unterlagen hochladen',
            self::ANALYSE => 'Automatische Analyse',
            self::OBJEKT_UND_EINHEITEN => 'Objekt, Vermieter und Einheiten',
            self::MIETVERHAELTNISSE => 'Mietverhältnisse und Zeitachse',
            self::KOSTENPRUEFUNG => 'Kostenprüfung',
            self::VORAUSZAHLUNGEN => 'Vorauszahlungen',
            self::VERTEILERSCHLUESSEL => 'Verteilerschlüssel und Verbrauch',
            self::PRUEFBERICHT => 'Prüfbericht',
            self::VORSCHAU => 'Vorschau und Bestätigung',
        };
    }

    /**
     * Kurzer Satz, was in diesem Schritt zu tun ist.
     */
    public function hint(): string
    {
        return match ($this) {
            self::KONTO_UND_ZEITRAUM => 'Legen Sie Objekt und Abrechnungszeitraum fest.',
            self::UPLOAD => 'Laden Sie alle Unterlagen hoch.',
            self::ANALYSE => 'Wir ordnen Ihre Unterlagen zu.',
            self::OBJEKT_UND_EINHEITEN => 'Prüfen Sie Objekt, Vermieter und Einheiten.',
            self::MIETVERHAELTNISSE => 'Prüfen Sie Mietverhältnisse, Leerstände und Zeiträume.',
            self::KOSTENPRUEFUNG => 'Bestätigen Sie die Kostenpositionen.',
            self::VORAUSZAHLUNGEN => 'Erfassen Sie die geleisteten Vorauszahlungen je Mietverhältnis.',
            self::VERTEILERSCHLUESSEL => 'Legen Sie je Kostenart den Verteilerschlüssel fest.',
            self::PRUEFBERICHT => 'Prüfen Sie die Ergebnisse der automatischen Prüfung.',
            self::VORSCHAU => 'Sehen Sie die Vorschau an und bestätigen Sie die Angaben.',
        };
    }

    /**
     * Routenname des Schritts. Die Schritte 1 bis 6 liegen in anderen
     * Bausteinen und werden nur verlinkt.
     */
    public function routeName(): string
    {
        return match ($this) {
            self::KONTO_UND_ZEITRAUM => 'portal.abrechnungen.show',
            self::UPLOAD => 'portal.uploads.index',
            self::ANALYSE => 'portal.pruefung.analyse',
            self::OBJEKT_UND_EINHEITEN => 'portal.abrechnungen.show',
            self::MIETVERHAELTNISSE => 'portal.abrechnungen.show',
            self::KOSTENPRUEFUNG => 'portal.pruefung.kosten',
            self::VORAUSZAHLUNGEN => 'portal.wizard.vorauszahlungen',
            self::VERTEILERSCHLUESSEL => 'portal.wizard.schluessel',
            self::PRUEFBERICHT => 'portal.wizard.pruefbericht',
            self::VORSCHAU => 'portal.wizard.vorschau',
        };
    }

    /**
     * Gehört der Schritt zu diesem Baustein?
     */
    public function isOwnStep(): bool
    {
        return $this->value >= self::VORAUSZAHLUNGEN->value;
    }

    public function previous(): ?self
    {
        return self::tryFrom($this->value - 1);
    }

    public function next(): ?self
    {
        return self::tryFrom($this->value + 1);
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }
}
