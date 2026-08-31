<?php

declare(strict_types=1);

namespace App\Application\BillingRun;

/**
 * Anzeigefertiger Status einer Zeile auf dem Dashboard.
 *
 * kategorie  eine der vier Kategorien aus PortalStatusCategory
 * hinweis    ein Satz in Alltagssprache, was zu tun ist
 * details    weitere Hinweise, jeweils ein Satz
 *
 * Es erscheint bewusst kein technischer Statuscode, kein Providername und keine
 * Ausnahmemeldung (Masterprompt 9, Schritt 3).
 */
final class PortalStatus
{
    /**
     * @param  list<string>  $details
     */
    public function __construct(
        public readonly string $kategorie,
        public readonly string $hinweis,
        public readonly array $details = [],
    ) {}

    public function variante(): string
    {
        return PortalStatusCategory::variant($this->kategorie);
    }

    public function blockiert(): bool
    {
        return $this->kategorie === PortalStatusCategory::BLOCKIERT;
    }
}
