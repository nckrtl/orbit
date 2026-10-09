<?php

declare(strict_types=1);

namespace App\Data\Instances;

use App\Actions\Instances\SelectInstanceSeedAction;
use App\Data\Nodes\NodeIdentityData;
use App\Data\Projects\ProjectIdentityData;
use App\Data\Routes\RouteData;
use App\Domain\AppDev\AnnotatorEndpoint;
use App\Domain\Instances\Deployment\InstanceDeployStepStore;
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
        public ?int $ssrPort = null,
        public ?string $seedPath = null,
        public ?string $seedCommit = null,
        public ?int $annotatorPort = null,
        public ?string $annotatorUrl = null,
    ) {}

    public static function fromModel(Instance $instance): self
    {
        if ($instance->name === 'default' && $instance->development_release_layout) {
            app(SelectInstanceSeedAction::class)->execute($instance);
        }
        $instance->loadMissing(['project', 'node', 'routes.targets', 'deploySteps']);
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
            ssrPort: $instance->ssr_port,
            seedPath: $instance->seed_path,
            seedCommit: $instance->seed_commit,
            annotatorPort: $instance->annotator_port,
            annotatorUrl: $instance->annotator_port !== null && $route instanceof Route ? AnnotatorEndpoint::origin($route->domain) : null,
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
        );
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
