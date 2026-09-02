<?php

declare(strict_types=1);

namespace App\Console\Commands\Install;

use App\Application\Install\CreateAdministrator;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Legt den ersten Administrator an (Masterprompt 20).
 *
 * Das Einmalpasswort wird genau einmal auf der Konsole ausgegeben. Es wird
 * nicht protokolliert und nicht per E-Mail versendet. Beim ersten Login wird
 * die Kennung direkt auf die Einrichtung des verpflichtenden Zweitfaktors
 * gefuehrt; der Adminbereich ist erst danach erreichbar.
 *
 * Idempotent: Existiert die Adresse, wird nur die Adminrolle gesetzt.
 */
final class CreateAdminCommand extends Command
{
    protected $signature = 'smartabrechnen:admin:create
        {--email= : E-Mail-Adresse des Administrators}
        {--name= : Anzeigename, Standard "Administration"}';

    protected $description = 'Legt den ersten Administrator mit Einmalpasswort an oder erteilt einer bestehenden Kennung die Adminrolle.';

    public function handle(CreateAdministrator $createAdministrator): int
    {
        $email = $this->option('email');
        $name = $this->option('name');

        if (! is_string($email) || trim($email) === '') {
            $this->error('Bitte geben Sie die Adresse an: php artisan smartabrechnen:admin:create --email=adresse@beispiel.de');

            return self::FAILURE;
        }

        try {
            $result = $createAdministrator->execute($email, is_string($name) ? $name : null);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Der Administrator konnte nicht angelegt werden ('.class_basename($exception).'). Bitte pruefen Sie die Datenbankverbindung und ob die Migrationen gelaufen sind.');

            return self::FAILURE;
        }

        $this->newLine();

        if ($result->userCreated) {
            $this->info(sprintf('Konto angelegt: %s', $result->user->getAttribute('email')));
            $this->line('E-Mail-Adresse: bestaetigt. Rolle: Administration.');
            $this->newLine();
            $this->line('Einmalpasswort (wird nur jetzt angezeigt, nicht protokolliert):');
            $this->newLine();
            $this->line('    '.$result->oneTimePassword);
            $this->newLine();
            $this->line('Naechste Schritte:');
            $this->line('  1. Unter '.rtrim((string) config('app.url'), '/').'/anmelden mit Adresse und Einmalpasswort anmelden.');
            $this->line('  2. Die Anmeldung fuehrt direkt zur Einrichtung des Zweitfaktors (Pflicht fuer Adminrollen).');
            $this->line('  3. Anschliessend ueber "Passwort vergessen" ein eigenes Passwort setzen und das Einmalpasswort verwerfen.');
        } else {
            $this->info(sprintf('Konto vorhanden: %s. Es wurde kein zweites Konto angelegt und kein Passwort geaendert.', $result->user->getAttribute('email')));
            $this->line($result->roleGranted ? 'Die Adminrolle wurde erteilt.' : 'Die Adminrolle war bereits aktiv.');

            if (! $result->twoFactorConfirmed) {
                $this->line('Der Zweitfaktor ist noch nicht eingerichtet. Die naechste Anmeldung fuehrt zur Einrichtung.');
            }
        }

        return self::SUCCESS;
    }
}
