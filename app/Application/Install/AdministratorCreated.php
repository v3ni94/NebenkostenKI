<?php

declare(strict_types=1);

namespace App\Application\Install;

use App\Models\User;

/**
 * Ergebnis von CreateAdministrator. Das Einmalpasswort ist nur fuer ein neu
 * angelegtes Konto gesetzt und darf ausschliesslich einmal auf der Konsole
 * ausgegeben werden.
 */
final readonly class AdministratorCreated
{
    public function __construct(
        public User $user,
        public bool $userCreated,
        public bool $roleGranted,
        public ?string $oneTimePassword,
        public bool $twoFactorConfirmed,
    ) {}
}
