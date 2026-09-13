<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use App\Infrastructure\Firewall\UfwManagedRule;
use App\Models\Node;

interface RouterLanIngressPublisher
{
    /** @param list<UfwManagedRule> $desired */
    public function expandRouterLanIngress(Node $router, array $desired, string $managedUser): void;

    /** @param list<UfwManagedRule> $desired */
    public function pruneRouterLanIngress(Node $router, array $desired, string $managedUser): void;
}
