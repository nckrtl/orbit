<?php

declare(strict_types=1);

namespace App\Data\Clusters;

use App\Domain\Clusters\ClusterState;
use Spatie\LaravelData\Data;

final class UpdateClusterData extends Data
{
    public function __construct(
        public bool $nameProvided,
        public ?string $name,
        public bool $tldProvided,
        public ?string $tld,
        public bool $stateProvided,
        public ?ClusterState $state,
    ) {}
}
