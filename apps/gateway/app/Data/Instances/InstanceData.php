<?php

declare(strict_types=1);

namespace App\Data\Instances;

use App\Data\Nodes\NodeIdentityData;
use App\Data\Projects\ProjectIdentityData;
use App\Data\Routes\RouteData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Instances\Deployment\InstanceDeployStepStore;
use App\Domain\Instances\InstanceCreation;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\InstanceTransfer;
use App\Models\Route;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class InstanceData extends Data
{
    public function __construct(
        public int $id,
        public int $projectId,
        public int $nodeId,
        public ProjectIdentityData $project,
        public NodeIdentityData $node,
        public string $name,
        public string $sourceLayout,
        public string $checkoutPath,
        public ?string $productionUser,
        public ?string $productionHome,
        public ?string $root,
        public ?string $effectiveRoot,
        public ?string $selectedBranch,
        public ?string $branchOverride,
        public ?string $startingCommit,
        public bool $detached,
        public string $status,
        public ?RouteData $route,
        public ?string $domain,
        public ?string $url,
        public ?InstanceRemovalData $removal,
        public ?InstanceTransferData $transfer,
        /** @var list<DeploymentStepData> */
        public array $deploySteps = [],
        public ?int $vitePort = null,
        public string $creation = 'repository',
        public ?string $copyMode = null,
        public ?InstanceSourceIdentityData $sourceInstance = null,
        /** @var list<InstanceSharedDatabaseData> */
        public array $sharedDatabases = [],
    ) {}

    public static function fromModel(Instance $instance): self
    {
        $instance->loadMissing(['project', 'node', 'routes.targets', 'deploySteps', 'sourceInstance']);
        $route = $instance->authoritativeRoute() ?? $instance->routes->first();
        $removal = InstanceRemoval::query()
            ->with('members')
            ->whereHas('members', static fn ($query) => $query
                ->where('instance_id', $instance->id)
                ->whereNull('row_deleted_at'))
            ->latest('created_at')
            ->first();

        return new self(
            id: $instance->id,
            projectId: $instance->project_id,
            nodeId: $instance->node_id,
            project: ProjectIdentityData::fromModel($instance->project),
            node: NodeIdentityData::fromModel($instance->node),
            vitePort: $instance->vite_port,
            name: $instance->name,
            sourceLayout: $instance->source_layout,
            checkoutPath: $instance->checkout_path,
            productionUser: $instance->production_user,
            productionHome: $instance->production_home,
            root: $instance->root,
            effectiveRoot: $instance->effectiveRoot(),
            selectedBranch: $instance->branch,
            branchOverride: $instance->branch_override,
            startingCommit: $instance->starting_commit,
            detached: $instance->registration_detached,
            status: $instance->status->value,
            route: $route instanceof Route ? RouteData::fromModel($route) : null,
            domain: $route?->domain,
            url: $route instanceof Route ? "https://{$route->domain}" : null,
            removal: $removal instanceof InstanceRemoval
                ? InstanceRemovalData::fromModel($removal)
                : null,
            transfer: self::transfer($instance),
            deploySteps: array_map(
                DeploymentStepData::fromDomain(...),
                app(InstanceDeployStepStore::class)->ordered($instance),
            ),
            creation: is_string($creation = $instance->getAttribute('creation')) && $creation !== '' ? $creation : 'repository',
            copyMode: is_string($instance->copy_mode) ? $instance->copy_mode : null,
            sourceInstance: $instance->sourceInstance instanceof Instance
                ? new InstanceSourceIdentityData($instance->sourceInstance->id, $instance->sourceInstance->name)
                : null,
            sharedDatabases: self::sharedDatabases($instance),
        );
    }

    /** @return list<InstanceSharedDatabaseData> */
    private static function sharedDatabases(Instance $instance): array
    {
        if ($instance->creation !== InstanceCreation::Copy) {
            return [];
        }

        $instance->loadMissing('databaseConnectionTargets.databaseConnection');
        $shared = [];

        foreach ($instance->databaseConnectionTargets as $target) {
            $connection = $target->databaseConnection;

            if (! in_array($connection->driver, [DatabaseDriver::Mysql, DatabaseDriver::Pgsql, DatabaseDriver::Redis], true)) {
                continue;
            }

            $shared[$connection->slug] = new InstanceSharedDatabaseData($connection->slug, $connection->driver->value);
        }

        ksort($shared);

        return array_values($shared);
    }

    private static function transfer(Instance $instance): ?InstanceTransferData
    {
        $transfer = InstanceTransfer::query()
            ->where('instance_id', $instance->id)
            ->latest('created_at')
            ->first();

        return $transfer instanceof InstanceTransfer
            ? InstanceTransferData::fromModel($transfer)
            : null;
    }
}
