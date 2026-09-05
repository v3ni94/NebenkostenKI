<?php

declare(strict_types=1);

namespace App\Application\FollowUpYear;

use RuntimeException;

/**
 * Es gibt keinen finalisierten Vorjahreslauf, aus dem uebernommen werden kann.
 *
 * Uebernommen wird ausschliesslich aus dem letzten FINALISIERTEN Lauf
 * (Masterprompt 8.3). Ein Entwurf oder ein abgebrochener Lauf ist keine
 * belastbare Grundlage.
 */
final class KeinFinalisierterVorjahreslaufException extends RuntimeException {}
