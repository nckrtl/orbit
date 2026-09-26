<?php

declare(strict_types=1);

namespace App\Domain\Activity;

use App\Models\Activity;

/**
 * Broadcasts an Activity notice when a model save stores a row or changes a column the notice
 * carries. Query-builder writes, including the interrupted-Activity sweep, bypass this observer
 * and broadcast themselves.
 */
final readonly class ActivityBroadcastObserver
{
    public function __construct(private ActivityBroadcaster $broadcaster) {}

    public function created(Activity $activity): void
    {
        $this->broadcaster->created($activity);
    }

    public function updated(Activity $activity): void
    {
        /** @var list<string> $columns */
        $columns = array_keys($activity->getChanges());

        $this->broadcaster->updated($activity, $columns);
    }
}
