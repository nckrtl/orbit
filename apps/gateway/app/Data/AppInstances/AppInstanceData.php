<?php

declare(strict_types=1);

namespace App\Data\AppInstances;

use App\Data\Apps\AppIdentityData;
use App\Data\Nodes\NodeIdentityData;
use App\Data\Routes\RouteData;
use App\Domain\AppInstances\Deployment\AppInstanceDeployStepStore;
use App\Models\AppInstance;
use App\Models\AppInstanceRemoval;
use App\Models\AppInstanceTransfer;
use App\Models\Route;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class AppInstanceData extends Data
{
    public function __construct(
        public int $id,
        public int $appId,
        public int $projectId,
        public int $nodeId,
        public AppIdentityData $app,
        public AppIdentityData $project,
        public NodeIdentityData $node,
        public string $name,
        public string $environment,
        public string $sourceLayout,
        public string $checkoutPath,
        public ?string $productionUser,
        public ?string $productionHome,
        public ?string $root,
        public ?string $effectiveRoot,
        public ?string $selectedBranch,
        public ?string $branchOverride,
        public bool $migrationRequired,
        public ?string $startingCommit,
        public bool $detached,
        public string $status,
        public ?RouteData $route,
        public ?string $domain,
        public ?string $url,
        public ?AppInstanceRemovalData $removal,
        public ?AppInstanceTransferData $transfer,
        /** @var list<DeploymentStepData> */
        public array $deploySteps = [],
        public ?int $vitePort = null,
    ) {}

    public static function fromModel(AppInstance $appInstance): self
    {
        $appInstance->loadMissing(['app', 'node', 'routes.targets', 'deploySteps']);
        $route = $appInstance->authoritativeRoute() ?? $appInstance->routes->first();
        $removal = AppInstanceRemoval::query()
            ->with('members')
            ->whereHas('members', static fn ($query) => $query
                ->where('app_instance_id', $appInstance->id)
                ->whereNull('row_deleted_at'))
            ->latest('created_at')
            ->first();

        return new self(
            id: $appInstance->id,
            appId: $appInstance->app_id,
            projectId: $appInstance->app_id,
            nodeId: $appInstance->node_id,
            app: AppIdentityData::fromModel($appInstance->app),
            project: AppIdentityData::fromModel($appInstance->app),
            node: NodeIdentityData::fromModel($appInstance->node),
            vitePort: $appInstance->vite_port,
            name: $appInstance->name,
            environment: $appInstance->placedOnAppProd() ? 'production' : 'development',
            sourceLayout: $appInstance->source_layout,
            checkoutPath: $appInstance->checkout_path,
            productionUser: $appInstance->production_user,
            productionHome: $appInstance->production_home,
            root: $appInstance->root,
            effectiveRoot: $appInstance->effectiveRoot(),
            selectedBranch: $appInstance->branch,
            branchOverride: $appInstance->branch_override,
            migrationRequired: $appInstance->migration_required,
            startingCommit: $appInstance->starting_commit,
            detached: $appInstance->registration_detached,
            status: $appInstance->status->value,
            route: $route instanceof Route ? RouteData::fromModel($route) : null,
            domain: $route?->domain,
            url: $route instanceof Route ? "https://{$route->domain}" : null,
            removal: $removal instanceof AppInstanceRemoval
                ? AppInstanceRemovalData::fromModel($removal)
                : null,
            transfer: self::transfer($appInstance),
            deploySteps: array_map(
                DeploymentStepData::fromDomain(...),
                app(AppInstanceDeployStepStore::class)->ordered($appInstance),
            ),
        );
    }

    private static function transfer(AppInstance $appInstance): ?AppInstanceTransferData
    {
        $transfer = AppInstanceTransfer::query()
            ->where('app_instance_id', $appInstance->id)
            ->latest('created_at')
            ->first();

        return $transfer instanceof AppInstanceTransfer
            ? AppInstanceTransferData::fromModel($transfer)
            : null;
    }
}
