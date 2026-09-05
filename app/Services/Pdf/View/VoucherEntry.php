<?php

declare(strict_types=1);

namespace App\Services\Pdf\View;

use App\Domain\Money\Money;
use DateTimeImmutable;

/**
 * Eintrag der optionalen Belegübersicht (Abschnitt 14.1).
 *
 * DATENSCHUTZ UND DATENSPARSAMKEIT: Der Eintrag stammt ausschließlich aus
 * strukturierten Extraktionsdaten. Er enthält bewusst KEINEN Dateipfad, KEINE
 * URL und KEINEN Originaldateinamen. Originaldateien werden weder eingebettet
 * noch verlinkt; sie werden nach der Auswertung gelöscht und liegen beim
 * Vermieter.
 */
final readonly class VoucherEntry
{
    public function __construct(
        public int $number,
        public string $categoryLabel,
        public ?string $issuer,
        public ?DateTimeImmutable $documentDate,
        public ?Money $amount,
        public ?string $documentTypeLabel = null,
    ) {}
}
