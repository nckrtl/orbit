<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Actions\Nodes\GrantGatewayRoleAccessAction;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleBaseline;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Processes\SystemdVpnOrderingDropIn;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class GatewayRoleBaseline implements RoleBaseline
{
    public function __construct(
        private NodeRoleFirewallManager $firewall,
        private PrivateDnsManager $dns,
        private NodeRolePrerequisiteCommandFactory $commands,
        private AppDevSshExecutor $ssh,
        private ?GrantGatewayRoleAccessAction $access = null,
        private SystemdVpnOrderingDropIn $vpnOrdering = new SystemdVpnOrderingDropIn,
    ) {}

    /**
     * Installs Caddy from the pinned source and orders it after `wg-quick@orbit` first, the same
     * step Gateway bootstrap runs, so converging the role repairs what Doctor reports for it.
     */
    public function converge(Node $node, NodeRole $assignment): void
    {
        $caddySource = $this->commands->caddySource($node, RoleName::Gateway);
        if ($caddySource instanceof RemoteCommand) {
            $this->ssh->execute($node, $caddySource, 'caddy-package-source', 'gateway.caddy_install_failed');
            $this->ssh->execute(
                $node,
                new RemoteCommand($this->vpnOrdering->arguments('caddy'), $this->vpnOrdering->script()),
                'caddy-ordering',
                'gateway.caddy_start_failed',
                60.0,
            );
        }
        $this->firewall->converge($node, RoleName::Gateway, $node->user);
        $this->grants()->execute($node);
        $this->dns->converge();
    }

    private function grants(): GrantGatewayRoleAccessAction
    {
        return $this->access ?? app(GrantGatewayRoleAccessAction::class);
    }

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $this->firewall->remove($node, RoleName::Gateway, $node->user);
        $this->dns->converge();
    }

    /**
     * Removes only what lives on the Gateway, for a gateway node Orbit
     * cannot reach: the private DNS record. Caddy, PHP-FPM, the serving
     * checkout, and the HTTPS firewall stay on the box.
     */
    public function removeUnreachable(Node $node, NodeRole $assignment): void
    {
        $this->dns->converge();
    }
}
