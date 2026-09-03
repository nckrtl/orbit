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
        public ?int $clusterId,
        public string $name,
        public string $environment,
        public string $sourceKind,
        public string $checkoutPath,
        public ?string $root,
        public ?string $effectiveRoot,
        public ?string $selectedBranch,
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
            clusterId: is_int($appInstance->cluster_id) ? $appInstance->cluster_id : null,
            name: $appInstance->name,
            environment: $appInstance->environment,
            sourceKind: $appInstance->source_kind,
            checkoutPath: $appInstance->checkout_path,
            root: $appInstance->root,
            effectiveRoot: $appInstance->effectiveRoot(),
            selectedBranch: $appInstance->branch,
            startingCommit: $appInstance->starting_commit,
            status: $appInstance->status->value,
        );
    }
}
