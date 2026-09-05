<?php

declare(strict_types=1);

namespace App\Console\Commands\Admin;

use App\Application\Account\AuditRecorder;
use App\Application\Account\TwoFactorAuthentication;
use App\Enums\AdminRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Notfall: Zweitfaktor einer Adminkennung ueber die Konsole zuruecksetzen.
 *
 * Der Adminbereich kann den Zweitfaktor fremder Konten zuruecksetzen, nie den
 * eigenen. Ist die einzige Adminkennung ausgesperrt (Telefon verloren, keine
 * Wiederherstellungscodes), bleibt nur der Weg ueber den Server. Dieser Befehl
 * ist bewusst eng gefasst:
 *
 *   - Nur Kennungen mit Adminrolle. Kundenkonten werden weiterhin ueber den
 *     Adminbereich zurueckgesetzt, dort mit Begruendung und Akteur.
 *   - Begruendung ist Pflicht und wird protokolliert.
 *   - Ausfuehrung nur mit ausdruecklicher Bestaetigung (--bestaetigt oder
 *     Rueckfrage), denn der Reset senkt fuer diese Kennung voruebergehend das
 *     Schutzniveau: Die naechste Anmeldung ist mit Passwort allein moeglich
 *     und fuehrt direkt auf die Einrichtung eines neuen Faktors.
 *   - Alle Datenbanksitzungen der Kennung werden beendet, das Merkmal
 *     "angemeldet bleiben" wird entzogen (TwoFactorAuthentication::reset).
 *
 * Der Vorgang setzt Identitaetspruefung und Vier-Augen-Prinzip VOR dem
 * Aufruf voraus, siehe docs/betrieb/installation.md, Abschnitt
 * Zweitfaktor-Notfall. Der Befehl kann das nicht erzwingen, er protokolliert
 * aber, wer laut Angabe bestaetigt hat.
 */
final class ResetTwoFactorCommand extends Command
{
    public const string AKTION = 'admin.user.two_factor_reset';

    public const int BEGRUENDUNG_MINDESTLAENGE = 15;

    protected $signature = 'smartabrechnen:admin:reset-2fa
        {--email= : E-Mail-Adresse der Adminkennung}
        {--grund= : Begruendung, mindestens 15 Zeichen, wird protokolliert}
        {--bestaetigt-von= : Name der zweiten Person, die den Vorgang bestaetigt hat}
        {--bestaetigt : Ohne Rueckfrage ausfuehren, etwa im Cronjob}';

    protected $description = 'Notfall: setzt den Zweitfaktor einer Adminkennung zurueck, beendet ihre Sitzungen und protokolliert den Vorgang.';

    public function handle(TwoFactorAuthentication $zweiFaktor, AuditRecorder $audit): int
    {
        $email = $this->option('email');
        $grund = $this->option('grund');
        $bestaetigtVon = $this->option('bestaetigt-von');

        if (! is_string($email) || trim($email) === '') {
            $this->error('Bitte geben Sie die Adresse an: php artisan smartabrechnen:admin:reset-2fa --email=adresse@beispiel.de --grund="..." --bestaetigt-von="..."');

            return self::FAILURE;
        }

        if (! is_string($grund) || mb_strlen(trim($grund)) < self::BEGRUENDUNG_MINDESTLAENGE) {
            $this->error(sprintf(
                'Bitte begruenden Sie den Vorgang nachvollziehbar (--grund, mindestens %d Zeichen). Die Begruendung wird protokolliert.',
                self::BEGRUENDUNG_MINDESTLAENGE,
            ));

            return self::FAILURE;
        }

        if (! is_string($bestaetigtVon) || trim($bestaetigtVon) === '') {
            $this->error('Bitte nennen Sie die zweite Person, die Identitaet und Vorgang bestaetigt hat (--bestaetigt-von). Vier-Augen-Prinzip, siehe Betriebshandbuch.');

            return self::FAILURE;
        }

        /** @var User|null $benutzer */
        $benutzer = User::query()->where('email', Str::lower(trim($email)))->first();

        if (! $benutzer instanceof User) {
            $this->error('Zu dieser Adresse gibt es kein Konto.');

            return self::FAILURE;
        }

        if (! $this->hatAdminrolle($benutzer)) {
            $this->error('Diese Kennung hat keine Adminrolle. Kundenkonten werden im Adminbereich unter Nutzer zurueckgesetzt.');

            return self::FAILURE;
        }

        if (! $zweiFaktor->isConfirmed($benutzer)) {
            $this->info('Fuer diese Kennung ist kein Zweitfaktor aktiv. Es wurde nichts geaendert.');

            return self::SUCCESS;
        }

        $this->warn('Der Zweitfaktor der Kennung '.$benutzer->getAttribute('email').' wird zurueckgesetzt.');
        $this->line('Alle Sitzungen dieser Kennung werden beendet. Die naechste Anmeldung ist mit Passwort allein moeglich und fuehrt direkt zur Einrichtung eines neuen Faktors.');

        if ($this->option('bestaetigt') !== true && ! $this->confirm('Identitaet geprueft und Vorgang im Vier-Augen-Prinzip bestaetigt. Fortfahren?', false)) {
            $this->info('Abgebrochen. Es wurde nichts geaendert.');

            return self::FAILURE;
        }

        try {
            $zweiFaktor->reset($benutzer);

            $audit->record(
                action: self::AKTION,
                subject: $benutzer,
                actor: null,
                metadata: [
                    'kanal' => 'konsole',
                    'sitzungen_beendet' => true,
                    'bestaetigt_von' => trim($bestaetigtVon),
                ],
                reason: trim($grund),
            );
        } catch (Throwable $exception) {
            $this->error('Der Zweitfaktor konnte nicht zurueckgesetzt werden ('.class_basename($exception).'). Bitte pruefen Sie die Datenbankverbindung.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Der Zweitfaktor wurde zurueckgesetzt und der Vorgang protokolliert.');
        $this->line('Naechste Schritte:');
        $this->line('  1. Unter '.rtrim((string) config('app.url'), '/').'/anmelden mit Adresse und Passwort anmelden.');
        $this->line('  2. Die Anmeldung fuehrt direkt zur Einrichtung des neuen Zweitfaktors. Wiederherstellungscodes sichern.');
        $this->line('  3. Den Vorgang im Betriebsprotokoll vermerken und die Cronjob-Ausgabe loeschen.');

        return self::SUCCESS;
    }

    private function hatAdminrolle(User $benutzer): bool
    {
        foreach (AdminRole::cases() as $rolle) {
            if ($benutzer->hasAdminRole($rolle)) {
                return true;
            }
        }

        return false;
    }
}
