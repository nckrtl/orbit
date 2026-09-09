<?php

declare(strict_types=1);

namespace App\Data\AppInstances;

use App\Data\Routes\RouteData;
use App\Models\AppInstance;
use App\Models\AppInstanceRemoval;
use App\Models\Route;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** @mago-expect lint:excessive-parameter-list The response includes its sole serving Route. */
#[MapOutputName(SnakeCaseMapper::class)]
final class AppInstanceData extends Data
{
    public function __construct(
        public int $id,
        public int $appId,
        public int $nodeId,
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
        public ?string $hostname,
        public ?string $url,
        public ?AppInstanceRemovalData $removal,
    ) {}

    public static function fromModel(AppInstance $appInstance): self
    {
        $appInstance->loadMissing(['app', 'routes.targets']);
        $route = $appInstance->routes->first();
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
            nodeId: $appInstance->node_id,
            name: $appInstance->name,
            environment: $appInstance->environment,
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
            hostname: $route?->hostname,
            url: $route instanceof Route ? "https://{$route->hostname}" : null,
            removal: $removal instanceof AppInstanceRemoval
                ? AppInstanceRemovalData::fromModel($removal)
                : null,
        );
    }
}
