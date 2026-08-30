<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\AppDevCaddyManager;
use App\Domain\AppDev\AppDevCertificateManager;
use App\Domain\AppDev\AppDevTldConverger;
use App\Domain\AppDev\AppDevTldRouteManager;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class NativeAppDevTldConverger implements AppDevTldConverger
{
    public function __construct(
        private AppDevCertificateManager $certificates,
        private AppDevCaddyManager $caddy,
        private PrivateDnsManager $dns,
        private AppDevTldRouteManager $routes,
    ) {}

    public function converge(Node $node): void
    {
        /** @var array{Collection<int, Instance>, Collection<int, Workspace>} $projections */
        $projections = DB::transaction(function () use ($node): array {
            $instances = Instance::query()->where('node_id', $node->id)->with('app')->get();
            $instanceIds = $instances->modelKeys();
            $hostnameById = $instances->mapWithKeys(
                fn (Instance $instance): array => [$instance->id => "{$instance->app->slug}.{$node->tld}"],
            );

            $workspaceRows = Workspace::query()->whereIn('instance_id', $instanceIds)->get();
            $workspaceHostnames = $workspaceRows->mapWithKeys(
                fn (Workspace $workspace): array => [
                    $workspace->id => "{$workspace->name}.{$hostnameById[$workspace->instance_id]}",
                ],
            );

            $instanceCollision = Instance::query()
                ->whereNotIn('id', $instanceIds)
                ->whereIn('hostname', $hostnameById->values())
                ->exists();
            $workspaceCollision = Workspace::query()
                ->whereNotIn('id', $workspaceRows->modelKeys())
                ->whereIn('hostname', $workspaceHostnames->values())
                ->exists();
            $crossInstanceCollision = Workspace::query()
                ->whereNotIn('instance_id', $instanceIds)
                ->whereIn('hostname', $hostnameById->values())
                ->exists();
            $crossWorkspaceCollision = Instance::query()
                ->whereNotIn('id', $instanceIds)
                ->whereIn('hostname', $workspaceHostnames->values())
                ->exists();

            if ($instanceCollision || $workspaceCollision || $crossInstanceCollision || $crossWorkspaceCollision) {
                throw new ResourceOperationException(
                    errorCode: 'node.tld_hostname_taken',
                    message: 'The new app-dev TLD would create a hostname conflict.',
                    status: 409,
                );
            }

            foreach ($instances as $instance) {
                $instance->update(['hostname' => $hostnameById[$instance->id]]);
            }
            foreach ($workspaceRows as $workspace) {
                $workspace->update(['hostname' => $workspaceHostnames[$workspace->id]]);
            }

            return [$instances->load('node'), $workspaceRows->load('instance.node')];
        });

        [$instances, $workspaces] = $projections;
        foreach ($instances as $instance) {
            if (in_array($instance->status, [LifecycleStatus::Active, LifecycleStatus::Provisioning], true)) {
                $this->certificates->convergeInstance($instance);
            }
        }
        foreach ($workspaces as $workspace) {
            if (
                in_array($workspace->status, [LifecycleStatus::Active, LifecycleStatus::Provisioning], true)
                && in_array(
                    $workspace->instance->status,
                    [LifecycleStatus::Active, LifecycleStatus::Provisioning],
                    true,
                )
            ) {
                $this->certificates->convergeWorkspace($workspace);
            }
        }
        $this->caddy->converge($node);
        $this->dns->converge($node);
        $this->routes->converge($node);
    }
}
