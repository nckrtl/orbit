<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\AppDev\AppDevCaddyManager;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleBaseline;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use App\Models\NodeRole;

/**
 * Installs the Caddy that serves the Ingress public sites, so an Ingress-only Node can serve them. It does not
 * build the Node Caddyfile: while the role converges it is not active, so a build here would drop the public
 * sites. Role convergence builds the Node after the role is active again. Public Route publication keeps owning
 * the public sites and the Ingress firewall rules.
 */
final readonly class IngressRoleBaseline implements RoleBaseline
{
    public function __construct(
        private NodeRolePrerequisiteCommandFactory $commands,
        private AppDevSshExecutor $ssh,
        private AppDevCaddyManager $caddy,
        private ManagedUserAccountResolver $accounts,
    ) {}

    public function converge(Node $node, NodeRole $assignment): void
    {
        $account = $this->accounts->resolve($node);
        $caddySource = $this->commands->caddySource($node, RoleName::Ingress);
        if ($caddySource instanceof RemoteCommand) {
            $this->ssh->execute($node, $caddySource, 'caddy-package-source', 'ingress.prerequisite_failed');
        }
        $this->ssh->execute(
            $node,
            $this->commands->make($node, RoleName::Ingress, $account),
            'role-prerequisites',
            'ingress.prerequisite_failed',
        );
    }

    /** The build renders the sites other roles still place on the Node. Caddy stays installed. */
    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $this->caddy->remove($node);
    }

    public function removeUnreachable(Node $node, NodeRole $assignment): void {}
}
