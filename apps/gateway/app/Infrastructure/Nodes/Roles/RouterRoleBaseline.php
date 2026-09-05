<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\AppDev\AppDevCaddyManager;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleBaseline;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class RouterRoleBaseline implements RoleBaseline
{
    public function __construct(
        private NodeRolePrerequisiteCommandFactory $commands,
        private AppDevSshExecutor $ssh,
        private AppDevCaddyManager $caddy,
        private NodeRoleFirewallManager $firewall,
        private ManagedUserAccountResolver $accounts,
    ) {}

    public function converge(Node $node, NodeRole $assignment): void
    {
        $account = $this->accounts->resolve($node);
        $this->ssh->execute(
            $node,
            $this->commands->make($node, RoleName::Router, $account),
            'role-prerequisites',
            'router.prerequisite_failed',
        );
        $this->caddy->converge($node);
        $this->firewall->converge($node, RoleName::Router, $account->user);
    }

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $account = $this->accounts->resolve($node);
        $this->caddy->remove($node);
        $this->firewall->remove($node, RoleName::Router, $account->user);
    }

    public function removeUnreachable(Node $node, NodeRole $assignment): void {}
}
