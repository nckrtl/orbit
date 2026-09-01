<?php

declare(strict_types=1);

namespace App\Data\AppInstances;

use App\Models\AppInstance;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** @mago-expect lint:excessive-parameter-list */
#[MapOutputName(SnakeCaseMapper::class)]
final class AppInstanceData extends Data
{
    public function __construct(
        public int $id,
        public int $appId,
        public int $nodeId,
        public int $clusterId,
        public string $name,
        public string $environment,
        public string $checkoutPath,
        public ?string $root,
        public ?string $effectiveRoot,
        public ?string $branch,
        public ?string $startingCommit,
        public string $status,
    ) {}

    public static function fromModel(AppInstance $appInstance): self
    {
        $appInstance->loadMissing('app');

        return new self(
            id: $appInstance->id,
            appId: $appInstance->app_id,
            nodeId: $appInstance->node_id,
            clusterId: $appInstance->cluster_id,
            name: $appInstance->name,
            environment: $appInstance->environment,
            checkoutPath: $appInstance->checkout_path,
            root: $appInstance->root,
            effectiveRoot: $appInstance->effectiveRoot(),
            branch: $appInstance->branch,
            startingCommit: $appInstance->starting_commit,
            status: $appInstance->status->value,
        );
    }
}
