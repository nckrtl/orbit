<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\AppDev\AppDevCaddyManager;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleBaseline;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\NodeStorageRootPreparer;
use App\Domain\Nodes\Storage\StorageRootResolver;
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
        private StorageRootResolver $storageRoots,
        private NodeSettingsNormalizer $nodeSettings,
        private NodeStorageRootPreparer $storageRootsPreparer,
    ) {}

    public function converge(Node $node, NodeRole $assignment): void
    {
        $account = $this->accounts->resolve($node);
        $this->storageRootsPreparer->prepare(
            $node,
            $account,
            $this->storageRoots->resolve($this->nodeSettings->fromStored($node->settings), $account),
        );
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
}
