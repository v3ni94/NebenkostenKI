<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
     * @return array<string, string>
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
            'boolean' => 'Bitte wählen Sie bei :attribute Ja oder Nein.',
            'date' => 'Bitte geben Sie bei :attribute ein gültiges Datum an.',
            'date_format' => 'Bitte geben Sie :attribute im Format TT.MM.JJJJ an.',
            'after_or_equal' => ':attribute darf nicht vor dem Beginn liegen.',
            'before_or_equal' => ':attribute darf nicht nach dem Ende liegen.',
            'integer' => 'Bitte geben Sie bei :attribute eine ganze Zahl an.',
            'numeric' => 'Bitte geben Sie bei :attribute eine Zahl an.',
            'decimal' => 'Bitte geben Sie bei :attribute eine Zahl an.',
            'max.string' => ':attribute darf höchstens :max Zeichen lang sein.',
            'max.numeric' => ':attribute darf höchstens :max betragen.',
            'min.string' => ':attribute muss mindestens :min Zeichen lang sein.',
            'min.numeric' => ':attribute muss mindestens :min betragen.',
            'size.string' => ':attribute muss genau :size Zeichen lang sein.',
            'regex' => 'Bitte prüfen Sie die Schreibweise bei :attribute.',
            'current_password' => 'Das eingegebene Passwort ist nicht richtig.',
        ], $this->eigeneMeldungen());
    }

    /**
     * Zusaetzliche Meldungen der konkreten Anfrage.
     *
     * @return array<string, string>
     */
    protected function eigeneMeldungen(): array
    {
        return [];
    }
}
