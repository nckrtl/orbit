<?php

declare(strict_types=1);

namespace App\Data\AppInstances;

use App\Data\Apps\AppData;
use App\Models\Instance;
use App\Models\Project;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class AppInstanceRegistrationData extends Data
{
    /** @param list<AppInstanceData> $instances */
    public function __construct(
        public AppData $app,
        public AppInstanceData $appInstance,
        public array $instances,
        public string $status,
        public int $sourceCount,
        public int $completedCount,
    ) {}

    /** @param list<Instance> $sourceInstances */
    public static function fromModels(Project $app, Instance $primary, array $sourceInstances): self
    {
        return new self(
            app: AppData::fromModel($app),
            appInstance: AppInstanceData::fromModel($primary),
            instances: array_map(AppInstanceData::fromModel(...), $sourceInstances),
            status: 'active',
            sourceCount: count($sourceInstances),
            completedCount: count($sourceInstances),
        );
    }
}
