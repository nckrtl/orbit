<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\AppDev\AppDevCaddyManager;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleBaseline;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Models\Node;
use App\Models\NodeRole;

/** @mago-expect lint:excessive-parameter-list Baseline wiring keeps infrastructure collaborators explicit at the composition boundary. */
final readonly class AppDevRoleBaseline implements RoleBaseline
{
    public function __construct(
        private NodeRolePrerequisiteCommandFactory $commands,
        private AppDevSshExecutor $ssh,
        private AppDevCaddyManager $caddy,
        private NodeRoleFirewallManager $firewall,
        private PrivateDnsManager $dns,
        private ManagedUserAccountResolver $accounts,
    ) {}

    public function converge(Node $node, NodeRole $assignment): void
    {
        $account = $this->accounts->resolve($node);
        $this->ssh->execute(
            $node,
            $this->commands->make($node, RoleName::AppDev, $account),
            'role-prerequisites',
            'app-dev.prerequisite_failed',
        );
        $this->caddy->converge($node);
        $this->firewall->converge($node, RoleName::AppDev, $node->user);
        $this->dns->converge($node);
    }

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $this->caddy->remove($node);
        $this->firewall->remove($node, RoleName::AppDev, $node->user);
        $this->dns->converge();
    }

    /**
     * Only the private DNS record lives on the Gateway.
     *
     * The Caddy route and the firewall rule both live on the node itself, so
     * both would have run over SSH; the caller reports those as retained.
     */
    public function removeUnreachable(Node $node, NodeRole $assignment): void
    {
        $this->dns->converge();
    }
}
