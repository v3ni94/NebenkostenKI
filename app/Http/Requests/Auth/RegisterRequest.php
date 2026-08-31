<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\GermanFormRequest;
use Illuminate\Validation\Rule;
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
                Rule::unique('users', 'email'),
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
            // Bewusst neutral formuliert. Die Meldung bestaetigt nicht, dass ein
            // Konto besteht. OFFENER PUNKT: Vollstaendig frei von
            // Kontoerkennung wird die Registrierung erst, wenn sie auch bei
            // vorhandener Adresse mit derselben Bestaetigungsseite antwortet und
            // stattdessen eine Hinweismail an die bestehende Adresse geht. Das
            // beruehrt den E-Mail-Versand und ist mit dem Kommunikationspaket
            // abzustimmen.
            'email.unique' => 'Mit dieser E-Mail-Adresse ist keine Registrierung möglich. '
                .'Falls Sie bereits ein Konto besitzen, melden Sie sich bitte an oder setzen Sie Ihr Passwort zurück.',
            'password.min' => 'Das Passwort muss mindestens 12 Zeichen lang sein.',
            'password.letters' => 'Das Passwort muss mindestens einen Buchstaben enthalten.',
            'password.numbers' => 'Das Passwort muss mindestens eine Ziffer enthalten.',
            'datenschutz.accepted' => 'Bitte bestätigen Sie die Kenntnisnahme der Datenschutzerklärung.',
        ];
    }
}
