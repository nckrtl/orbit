<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Models\AppInstance;
use App\Models\AppInstanceEnvironmentValue;

final readonly class AgentationUrlProjection
{
    public function project(AppInstance $instance): void
    {
        AppInstanceEnvironmentValue::query()->updateOrCreate(
            [
                'app_instance_id' => $instance->id,
                'env_key' => AgentationEndpoint::URL_KEY,
            ],
            ['env_value' => AgentationEndpoint::STORED_URL],
        );
    }

    public function forget(AppInstance $instance): void
    {
        AppInstanceEnvironmentValue::query()
            ->where('app_instance_id', $instance->id)
            ->where('env_key', AgentationEndpoint::URL_KEY)
            ->delete();
    }
}
