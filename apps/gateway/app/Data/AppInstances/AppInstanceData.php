<?php

declare(strict_types=1);

namespace App\Data\AppInstances;

use App\Data\Routes\RouteData;
use App\Models\AppInstance;
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
        public string $sourceKind,
        public string $checkoutPath,
        public ?string $root,
        public ?string $effectiveRoot,
        public ?string $selectedBranch,
        public ?string $startingCommit,
        public string $status,
        public ?RouteData $route,
        public ?string $hostname,
        public ?string $url,
    ) {}

    public static function fromModel(AppInstance $appInstance): self
    {
        $appInstance->loadMissing(['app', 'routes.targets']);
        $route = $appInstance->routes->first();

        return new self(
            id: $appInstance->id,
            appId: $appInstance->app_id,
            nodeId: $appInstance->node_id,
            name: $appInstance->name,
            environment: $appInstance->environment,
            sourceKind: $appInstance->source_kind,
            checkoutPath: $appInstance->checkout_path,
            root: $appInstance->root,
            effectiveRoot: $appInstance->effectiveRoot(),
            selectedBranch: $appInstance->branch,
            startingCommit: $appInstance->starting_commit,
            status: $appInstance->status->value,
            route: $route instanceof Route ? RouteData::fromModel($route) : null,
            hostname: $route?->hostname,
            url: $route instanceof Route ? "https://{$route->hostname}" : null,
        );
    }
}
