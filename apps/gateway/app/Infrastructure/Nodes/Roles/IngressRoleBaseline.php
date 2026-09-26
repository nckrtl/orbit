<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\AppDev\AppDevCaddyManager;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleBaseline;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use App\Models\NodeRole;

/**
 * Installs the Caddy that serves the Ingress public sites, so an Ingress-only Node can serve them, and builds the
 * Node Caddyfile. A converging Ingress still serves its public sites, so the build keeps them. Public Route
 * publication opens the Ingress firewall rules. Removal builds the Node without the Ingress and closes those rules.
 */
final readonly class IngressRoleBaseline implements RoleBaseline
{
    public function __construct(
        private NodeRolePrerequisiteCommandFactory $commands,
        private AppDevSshExecutor $ssh,
        private AppDevCaddyManager $caddy,
        private ManagedUserAccountResolver $accounts,
        private NodeRoleFirewallManager $firewall,
    ) {}

    public function converge(Node $node, NodeRole $assignment): void
    {
        $account = $this->accounts->resolve($node);
        $caddySource = $this->commands->caddySource($node, RoleName::Ingress);
        if ($caddySource instanceof RemoteCommand) {
            $this->ssh->execute($node, $caddySource, 'caddy-package-source', 'ingress.prerequisite_failed', failureLabel: CaddyRoleFailure::sshLabel(RoleName::Ingress));
        }
        $this->ssh->execute(
            $node,
            $this->commands->make($node, RoleName::Ingress, $account),
            'role-prerequisites',
            'ingress.prerequisite_failed',
            failureLabel: CaddyRoleFailure::sshLabel(RoleName::Ingress),
        );
        $this->caddy->converge($node, RoleName::Ingress);
    }

    /**
     * The claimed assignment is `removing`, so the build renders only the sites other roles still place on the
     * Node: no public site, and no listener on every address. The public HTTP and HTTPS rules close after the
     * build, so no public port stays open once nothing serves on it. Caddy stays installed. Both steps repeat
     * safely on a retry.
     */
    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $this->caddy->remove($node, RoleName::Ingress);
        $this->firewall->remove($node, RoleName::Ingress, $this->accounts->resolve($node)->user);
    }

    /** Nothing runs on an unreachable Node. The removal reports its Caddy sites and firewall rules as retained. */
    public function removeUnreachable(Node $node, NodeRole $assignment): void {}
}
