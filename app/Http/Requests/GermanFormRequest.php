<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Basis aller Formularanfragen der Anwendung.
 *
 * Vorgabe des Masterprompts, Abschnitt 12 und ARCHITECTURE.md Grundsatz 11:
 * Oberflaeche und Meldungen sind vollstaendig deutsch, in Sie-Ansprache, klar
 * und ohne Gedankenstriche. Ein technischer Stacktrace erreicht den Nutzer
 * niemals.
 *
 * Die deutschen Texte stehen bewusst hier und nicht in einer Sprachdatei unter
 * lang/. Die Sprachdateien gehoeren keinem Arbeitspaket dieses Stands an, und
 * eine halb uebersetzte Sprachdatei waere schlechter als vollstaendige
 * Meldungen an der Regel selbst. Untertypen ergaenzen ihre eigenen Texte ueber
 * eigeneMeldungen() und beschriften ihre Felder ueber attributes().
 */
abstract class GermanFormRequest extends FormRequest
{
    /**
     * Allgemeine deutsche Meldungen fuer die haeufigen Regeln.
     *
     * @return array<string, string|array<string, string>>
     */
    public function messages(): array
    {
        return array_merge([
            'required' => 'Bitte füllen Sie das Feld :attribute aus.',
            'required_if' => 'Bitte füllen Sie das Feld :attribute aus.',
            'required_with' => 'Bitte füllen Sie das Feld :attribute aus.',
            'string' => 'Bitte geben Sie bei :attribute einen Text ein.',
            'email' => 'Bitte geben Sie eine gültige E-Mail-Adresse ein.',
            'confirmed' => 'Die Wiederholung von :attribute stimmt nicht überein.',
            'unique' => 'Dieser Wert ist bereits vergeben.',
            'exists' => 'Die getroffene Auswahl ist nicht verfügbar.',
            'in' => 'Bitte treffen Sie eine gültige Auswahl bei :attribute.',
            'not_in' => 'Bitte treffen Sie eine gültige Auswahl bei :attribute.',
            // Die Regel enum ist ein Regelobjekt. Eine allgemeine Inline-Meldung
            // wird dafuer unter dem Klassennamen gesucht; ohne sie faellt die
            // Regel auf einen fest verdrahteten englischen Text zurueck.
            Enum::class => 'Bitte treffen Sie eine gültige Auswahl bei :attribute.',
            'in_array' => 'Bitte treffen Sie eine gültige Auswahl bei :attribute.',
            'boolean' => 'Bitte wählen Sie bei :attribute Ja oder Nein.',
            'accepted' => 'Bitte bestätigen Sie :attribute.',
            'declined' => 'Bitte lehnen Sie :attribute ab.',
            'array' => 'Die Angaben bei :attribute haben ein ungültiges Format. Bitte laden Sie die Seite neu und versuchen Sie es erneut.',
            'required_array_keys' => 'Die Angaben bei :attribute sind unvollständig. Bitte laden Sie die Seite neu und versuchen Sie es erneut.',
            'distinct' => 'Bei :attribute darf kein Wert doppelt vorkommen.',
            'file' => 'Bitte wählen Sie bei :attribute eine gültige Datei aus.',
            'image' => 'Bitte wählen Sie bei :attribute ein gültiges Bild aus.',
            'mimes' => 'Das Dateiformat bei :attribute wird nicht unterstützt.',
            'mimetypes' => 'Das Dateiformat bei :attribute wird nicht unterstützt.',
            'url' => 'Bitte geben Sie bei :attribute eine gültige Internetadresse an.',
            'uuid' => 'Der Wert bei :attribute ist ungültig.',
            'ulid' => 'Der Wert bei :attribute ist ungültig.',
            'digits' => ':attribute muss aus genau :digits Ziffern bestehen.',
            'digits_between' => ':attribute muss aus :min bis :max Ziffern bestehen.',
            'alpha_num' => 'Bitte verwenden Sie bei :attribute nur Buchstaben und Ziffern.',
            'alpha_dash' => 'Bitte verwenden Sie bei :attribute nur Buchstaben, Ziffern, Bindestriche und Unterstriche.',
            'present' => 'Das Feld :attribute muss übermittelt werden.',
            'prohibited' => 'Das Feld :attribute darf nicht übermittelt werden.',
            'required_without' => 'Bitte füllen Sie das Feld :attribute aus.',
            'required_unless' => 'Bitte füllen Sie das Feld :attribute aus.',
            'same' => ':attribute muss mit :other übereinstimmen.',
            'different' => ':attribute muss sich von :other unterscheiden.',
            'after' => ':attribute muss nach :date liegen.',
            'before' => ':attribute muss vor :date liegen.',
            /*
             * Groessenregeln: Laravel waehlt die Meldung nach dem Typ des
             * Feldes (numeric, string, array, file). Als Inline-Meldung muss
             * dafuer je Regel ein Array mit den Typen uebergeben werden;
             * Schluessel wie "max.string" werden nur in Sprachdateien
             * ausgewertet und blieben hier wirkungslos.
             */
            'gte' => [
                'numeric' => ':attribute muss mindestens :value betragen.',
                'string' => ':attribute muss mindestens :value Zeichen lang sein.',
                'array' => 'Bei :attribute sind mindestens :value Einträge erforderlich.',
                'file' => 'Die Datei bei :attribute muss mindestens :value Kilobyte groß sein.',
            ],
            'lte' => [
                'numeric' => ':attribute darf höchstens :value betragen.',
                'string' => ':attribute darf höchstens :value Zeichen lang sein.',
                'array' => 'Bei :attribute sind höchstens :value Einträge zulässig.',
                'file' => 'Die Datei bei :attribute darf höchstens :value Kilobyte groß sein.',
            ],
            'gt' => [
                'numeric' => ':attribute muss größer als :value sein.',
                'string' => ':attribute muss länger als :value Zeichen sein.',
                'array' => 'Bei :attribute sind mehr als :value Einträge erforderlich.',
                'file' => 'Die Datei bei :attribute muss größer als :value Kilobyte sein.',
            ],
            'lt' => [
                'numeric' => ':attribute muss kleiner als :value sein.',
                'string' => ':attribute muss kürzer als :value Zeichen sein.',
                'array' => 'Bei :attribute sind weniger als :value Einträge zulässig.',
                'file' => 'Die Datei bei :attribute muss kleiner als :value Kilobyte sein.',
            ],
            'between' => [
                'numeric' => ':attribute muss zwischen :min und :max liegen.',
                'string' => ':attribute muss zwischen :min und :max Zeichen lang sein.',
                'array' => 'Bei :attribute sind zwischen :min und :max Einträge zulässig.',
                'file' => 'Die Datei bei :attribute muss zwischen :min und :max Kilobyte groß sein.',
            ],
            'date' => 'Bitte geben Sie bei :attribute ein gültiges Datum an.',
            'date_format' => 'Bitte geben Sie :attribute im Format TT.MM.JJJJ an.',
            'after_or_equal' => ':attribute darf nicht vor dem Beginn liegen.',
            'before_or_equal' => ':attribute darf nicht nach dem Ende liegen.',
            'integer' => 'Bitte geben Sie bei :attribute eine ganze Zahl an.',
            'numeric' => 'Bitte geben Sie bei :attribute eine Zahl an.',
            'decimal' => 'Bitte geben Sie bei :attribute eine Zahl an.',
            'max' => [
                'string' => ':attribute darf höchstens :max Zeichen lang sein.',
                'numeric' => ':attribute darf höchstens :max betragen.',
                'array' => 'Bei :attribute sind höchstens :max Einträge zulässig.',
                'file' => 'Die Datei bei :attribute darf höchstens :max Kilobyte groß sein.',
            ],
            'min' => [
                'string' => ':attribute muss mindestens :min Zeichen lang sein.',
                'numeric' => ':attribute muss mindestens :min betragen.',
                'array' => 'Bei :attribute sind mindestens :min Einträge erforderlich.',
                'file' => 'Die Datei bei :attribute muss mindestens :min Kilobyte groß sein.',
            ],
            'size' => [
                'string' => ':attribute muss genau :size Zeichen lang sein.',
                'numeric' => ':attribute muss genau :size betragen.',
                'array' => 'Bei :attribute sind genau :size Einträge erforderlich.',
                'file' => 'Die Datei bei :attribute muss genau :size Kilobyte groß sein.',
            ],
            'regex' => 'Bitte prüfen Sie die Schreibweise bei :attribute.',
            'current_password' => 'Das eingegebene Passwort ist nicht richtig.',
        ], $this->eigeneMeldungen(), $this->enumMeldungenJeFeld());
    }

    /**
     * Feldbezogene Meldungen fuer die Regel enum ("feld.enum") werden vom
     * Validator erst nach der allgemeinen Meldung unter dem Klassennamen
     * gesucht. Damit die eigene Meldung einer Anfrage weiterhin Vorrang hat,
     * wird sie zusaetzlich unter "feld.<Klassenname>" hinterlegt.
     *
     * @return array<string, string>
     */
    private function enumMeldungenJeFeld(): array
    {
        $meldungen = [];

        foreach ($this->eigeneMeldungen() as $schluessel => $meldung) {
            if (is_string($meldung) && str_ends_with($schluessel, '.enum')) {
                $meldungen[substr($schluessel, 0, -5).'.'.Enum::class] = $meldung;
            }
        }

        return $meldungen;
    }

    /**
     * Zusaetzliche Meldungen der konkreten Anfrage.
     *
     * @return array<string, string|array<string, string>>
     */
    protected function eigeneMeldungen(): array
    {
        return [];
    }
}
