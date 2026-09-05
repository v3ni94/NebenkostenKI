<?php

declare(strict_types=1);

namespace App\Application\Review\Dto;

/**
 * Pflicht-Warnhinweis der Kostenpruefung.
 *
 * Der Hinweis erklaert allgemein und ohne Rechtsberatung im Einzelfall,
 * warum eine Position besonders zu pruefen ist.
 */
final readonly class WarningBanner
{
    public const string KIND_NOT_ALLOCABLE = 'NICHT_UMLAGEFAEHIG';

    public const string KIND_OUTSIDE_PERIOD = 'ZEITRAUM_ABGRENZUNG';

    public const string KIND_DUPLICATE = 'DUBLETTE';

    public const string KIND_LOW_CONFIDENCE = 'KONFIDENZ';

    /**
     * @param  list<string>  $costItemIds
     */
    public function __construct(
        public string $kind,
        public string $title,
        public string $text,
        public string $variant = 'warning',
        public array $costItemIds = [],
    ) {}
}
