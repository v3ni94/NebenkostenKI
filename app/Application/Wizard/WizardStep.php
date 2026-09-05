<?php

declare(strict_types=1);

namespace App\Application\Wizard;

/**
 * Die zwölf Schritte des geführten Ablaufs (Masterprompt Abschnitt 9, Website
 * "So funktioniert es").
 *
 * Der Schritt wird im Abrechnungslauf gespeichert (Spalte wizard_step). Der
 * Nutzer kann jederzeit unterbrechen und ohne Datenverlust fortsetzen; die
 * Zurück-Navigation ist immer erlaubt, weil jeder Schritt seine Daten sofort
 * speichert.
 *
 * Die Schritte 1 bis 6 werden von anderen Bausteinen bereitgestellt und hier
 * ausschließlich verlinkt. Die Schritte 11 (Zahlung) und 12 (Finalisierung)
 * liegen hinter der Bestätigung der Vorschau; ihr Stand ergibt sich aus dem
 * Laufstatus, nicht aus der gespeicherten Schrittnummer. Damit zählen
 * Schrittanzeige, Seitenköpfe, Zahlung und Abschluss dieselben zwölf Schritte
 * wie die Website.
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
    case ZAHLUNG = 11;
    case ABSCHLUSS = 12;

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
            self::ZAHLUNG => 'Zahlung',
            self::ABSCHLUSS => 'Finalisierung',
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
            self::ZAHLUNG => 'Zahlen Sie einmalig je erzeugter Mieterabrechnung.',
            self::ABSCHLUSS => 'Laden Sie die finalen Abrechnungen und die Rechnung herunter.',
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
            self::ZAHLUNG => 'portal.checkout.show',
            self::ABSCHLUSS => 'portal.abschluss.show',
        };
    }

    /**
     * Gehört der Schritt zu diesem Baustein?
     */
    public function isOwnStep(): bool
    {
        return $this->value >= self::VORAUSZAHLUNGEN->value && $this->value <= self::VORSCHAU->value;
    }

    /**
     * Einordnung für den Seitenkopf, z. B. "Schritt 7 von 12". Einzige Quelle
     * der Zählung, damit Eyebrow, Schrittanzeige und Wiedereinstieg übereinstimmen.
     */
    public function eyebrow(): string
    {
        return sprintf('Schritt %d von %d', $this->value, self::count());
    }

    public static function count(): int
    {
        return count(self::cases());
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
