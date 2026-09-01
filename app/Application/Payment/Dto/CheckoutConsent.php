<?php

declare(strict_types=1);

namespace App\Application\Payment\Dto;

/**
 * Zustimmungen aus dem Checkoutformular (Abschnitt 2.3).
 *
 * Beide Kaestchen sind im Formular NICHT vorangekreuzt. Ohne die gesonderte
 * Zustimmung zur sofortigen Vertragsausfuehrung und ohne die Bestaetigung der
 * Vertragsgrundlagen wird kein Checkout eingeleitet.
 *
 * Gespeichert werden ausschliesslich Textfassung, Zweck, Zeitpunkt, gekuerzte
 * IP und gehashter User-Agent, niemals der vollstaendige Fingerabdruck des
 * Nutzers.
 */
final readonly class CheckoutConsent
{
    public function __construct(
        public bool $immediatePerformance,
        public bool $terms,
    ) {}

    public function isComplete(): bool
    {
        return $this->immediatePerformance && $this->terms;
    }
}
