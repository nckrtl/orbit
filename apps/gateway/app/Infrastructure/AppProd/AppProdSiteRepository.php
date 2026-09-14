<?php

declare(strict_types=1);

namespace App\Infrastructure\AppProd;

use App\Models\Node;
use Illuminate\Support\Collection;

final readonly class AppProdSiteRepository
{
    /** @return Collection<int, AppProdSite> */
    public function forNode(Node $node): Collection
    {
        return collect();
    }

    public function hasLivePublicFootprint(Node $node): bool
    {
        return false;
    }

    public function requiresPublicFirewall(Node $node): bool
    {
        return false;
    }
}
