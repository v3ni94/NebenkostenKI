<?php

declare(strict_types=1);

namespace App\Services\Pdf\View;

/**
 * Absender der Mieterabrechnung (Abschnitt 2.2).
 *
 * Absender und inhaltlich Verantwortlicher ist der Vermieter beziehungsweise
 * Eigentümer, niemals automatisch die Hausverwaltung Müller GmbH. Die
 * Mieterabrechnung trägt daher kein HVM-Logo, keine HVM-Kennlinie und keine
 * HVM-Farben.
 */
final readonly class LandlordSender
{
    public function __construct(
        public PostalAddress $address,
        public ?string $contactPhone = null,
        public ?string $contactEmail = null,
        public ?BankAccount $bankAccount = null,
    ) {}
}
