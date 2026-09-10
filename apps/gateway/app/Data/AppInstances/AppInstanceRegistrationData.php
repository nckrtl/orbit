<?php

declare(strict_types=1);

namespace App\Data\AppInstances;

use App\Data\Apps\AppData;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class AppInstanceRegistrationData extends Data
{
    /** @param list<AppInstanceData> $appInstances */
    public function __construct(
        public AppData $app,
        public AppInstanceData $appInstance,
        public array $appInstances,
        public string $status,
        public int $sourceCount,
        public int $completedCount,
    ) {}

    /** @param list<AppInstance> $instances */
    public static function fromModels(OrbitApp $app, AppInstance $primary, array $instances): self
    {
        return new self(
            app: AppData::fromModel($app),
            appInstance: AppInstanceData::fromModel($primary),
            appInstances: array_map(AppInstanceData::fromModel(...), $instances),
            status: 'active',
            sourceCount: count($instances),
            completedCount: count($instances),
        );
    }
}
