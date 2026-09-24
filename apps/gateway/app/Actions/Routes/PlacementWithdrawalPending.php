<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use RuntimeException;
use Throwable;

/**
 * A placement change has moved private DNS away from the placement it stops serving. The
 * withdrawal waits until cached answers can have expired, outside every projection lock, and then
 * reads the Route again before it withdraws. A restore carries the failure it reports afterwards.
 */
final class PlacementWithdrawalPending extends RuntimeException
{
    public function __construct(
        public readonly int $routeId,
        public readonly ?Throwable $failure = null,
    ) {
        parent::__construct('A placement change waits for private DNS answers to expire.');
    }

    public function restores(): bool
    {
        return $this->failure instanceof Throwable;
    }
}
