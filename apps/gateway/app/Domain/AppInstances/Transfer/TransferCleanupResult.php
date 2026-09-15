<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Transfer;

final readonly class TransferCleanupResult
{
    /**
     * @param  list<string>  $incomplete
     */
    public function __construct(
        public bool $sourcePlacementRemoved,
        public bool $commonRepositoryPreserved,
        public array $incomplete = [],
    ) {}
}
