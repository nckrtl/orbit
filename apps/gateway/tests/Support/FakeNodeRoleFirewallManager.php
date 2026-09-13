<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleName;
use App\Models\Node;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

final class FakeNodeRoleFirewallManager implements NodeRoleFirewallManager
{
    /** @var list<int> */
    public array $restored = [];

    /** @var list<string> */
    public array $restoredUsers = [];

    /** @var list<string> */
    public array $events = [];

    public ?Throwable $restoreFailure = null;

    public ?Closure $onRestore = null;

    public function convergeBase(Node $node, string $managedUser): void {}

    public function converge(Node $node, RoleName $role, string $managedUser): void {}

    public function remove(Node $node, RoleName $role, string $managedUser): void {}

    public function restorePublicSsh(Node $node, string $managedUser): void
    {
        $this->restored[] = $node->id;
        $this->restoredUsers[] = $managedUser;
        $this->events[] = 'firewall-recovery:'.DB::transactionLevel();

        if ($this->onRestore instanceof Closure) {
            ($this->onRestore)($node);
        }

        if ($this->restoreFailure instanceof Throwable) {
            throw $this->restoreFailure;
        }
    }
}
