<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Umlagebewertung einer Kostenart oder Kostenposition.
 *
 * PRUEFPFLICHTIG bedeutet: keine automatische Umlage, ausdrueckliche
 * Nutzerentscheidung mit Begruendung erforderlich. Keine Rechtsfreigabe.
 */
enum ApportionmentStatus: string
{
    case UMLAGEFAEHIG = 'UMLAGEFAEHIG';
    case NICHT_UMLAGEFAEHIG = 'NICHT_UMLAGEFAEHIG';
    case PRUEFPFLICHTIG = 'PRUEFPFLICHTIG';

    /**
     * Deutscher Anzeigetext fuer Oberflaeche, PDF und E-Mail.
     */
    public function label(): string
    {
        return match ($this) {
            self::UMLAGEFAEHIG => 'Umlagefähig',
            self::NICHT_UMLAGEFAEHIG => 'Nicht umlagefähig',
            self::PRUEFPFLICHTIG => 'Prüfpflichtig',
        };
    }

    /**
     * Darf ohne ausdrueckliche Nutzerentscheidung umgelegt werden.
     */
    public function isAutomaticallyApportionable(): bool
    {
        return $this === self::UMLAGEFAEHIG;
    }
}
