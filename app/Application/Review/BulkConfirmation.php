<?php

declare(strict_types=1);

namespace App\Application\Review;

use App\Models\BillingRun;
use App\Models\CostItem;
use App\Models\User;

/**
 * Sammelbestaetigung der Kostenpruefung.
 *
 * VERBINDLICH: Bestaetigt werden ausschliesslich konfliktfreie und
 * hochkonfidente Positionen. Nicht umlagefaehige, pruefpflichtige,
 * dublettenverdaechtige, zeitlich abweichende und niedrigkonfidente Positionen
 * werden uebersprungen und muessen einzeln behandelt werden.
 *
 * Die Auswahl trifft ausschliesslich der CostReviewPresenter. Eine vom Browser
 * uebergebene Liste wird gegen diese Auswahl geprueft; ein manipulierter
 * Aufruf kann damit keine konfliktbehaftete Position bestaetigen.
 */
final class BulkConfirmation
{
    public function __construct(
        private readonly CostReviewPresenter $presenter,
        private readonly CostItemDecisions $decisions,
    ) {}

    /**
     * @param  list<string>|null  $requestedIds
     * @return array{confirmed: int, skipped: int, skippedIds: list<string>}
     */
    public function confirm(BillingRun $billingRun, User $user, ?array $requestedIds = null): array
    {
        $overview = $this->presenter->overview($billingRun);
        $allowed = $overview->bulkConfirmableIds;

        $ids = $requestedIds === null
            ? $allowed
            : array_values(array_intersect($requestedIds, $allowed));

        $skipped = [];

        foreach ($overview->groups as $group) {
            foreach ($group->positions as $position) {
                if (! $position->decided && ! in_array($position->id, $ids, true)) {
                    $skipped[] = $position->id;
                }
            }
        }

        $confirmed = 0;

        foreach ($ids as $id) {
            $item = CostItem::query()
                ->where('billing_run_id', $billingRun->getKey())
                ->whereKey($id)
                ->first();

            if (! $item instanceof CostItem) {
                continue;
            }

            $this->decisions->confirm($billingRun, $item, $user);
            $confirmed++;
        }

        return [
            'confirmed' => $confirmed,
            'skipped' => count($skipped),
            'skippedIds' => array_values($skipped),
        ];
    }
}
