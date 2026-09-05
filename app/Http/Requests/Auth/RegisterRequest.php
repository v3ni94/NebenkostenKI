<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\GermanFormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Registrierung mit E-Mail und Passwort.
 *
 * Die Passwortregel folgt der Empfehlung, Laenge vor Zeichenklassenzwang zu
 * stellen. Zwoelf Zeichen mit Buchstaben und Ziffern sind die Untergrenze.
 *
 * OFFENER PUNKT: Password::uncompromised() prueft gegen die Liste bekannter
 * geleakter Passwoerter und benoetigt dafuer eine ausgehende HTTPS-Verbindung
 * zu einem Dritten. Die Pruefung ist datenschutzrechtlich bewertbar, weil nur
 * die ersten fuenf Zeichen eines SHA-1-Praefix uebertragen werden. Sie ist vor
 * Livegang zu entscheiden und in der Datenschutzerklaerung zu erwaehnen.
 * Bis dahin ist sie bewusst nicht aktiviert.
 *
 * KEINE KONTOERKENNUNG
 *
 * Die Regel unique auf users.email ist bewusst ENTFERNT. Sie hat verraten, ob zu
 * einer Adresse ein Konto besteht. Die Entscheidung faellt jetzt im Controller
 * App\Http\Controllers\Auth\RegisteredUserController: Bei einer bereits
 * registrierten Adresse antwortet die Anwendung mit derselben Bestaetigung,
 * legt kein zweites Konto an und sendet stattdessen eine sachliche Hinweismail
 * an die bestehende Adresse.
 *
 * Alle uebrigen Regeln bleiben unveraendert scharf: Format und Laenge der
 * Adresse, Passwortlaenge, Buchstaben und Ziffern, Wiederholung und die
 * Einwilligung.
 */
class RegisterRequest extends GermanFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:190',
            ],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(12)->letters()->numbers(),
            ],
            'datenschutz' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'Name',
            'email' => 'E-Mail-Adresse',
            'password' => 'Passwort',
            'datenschutz' => 'Einwilligung',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function eigeneMeldungen(): array
    {
        return [
            'password.min' => 'Das Passwort muss mindestens 12 Zeichen lang sein.',
            'password.letters' => 'Das Passwort muss mindestens einen Buchstaben enthalten.',
            'password.numbers' => 'Das Passwort muss mindestens eine Ziffer enthalten.',
            'datenschutz.accepted' => 'Bitte bestätigen Sie die Kenntnisnahme der Datenschutzerklärung.',
        ];
    }
}
