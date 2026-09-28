<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Models\AppInstanceEnvironmentValue;
use App\Models\Instance;

final readonly class AgentationUrlProjection
{
    public function project(Instance $instance): void
    {
        AppInstanceEnvironmentValue::query()->updateOrCreate(
            [
                'instance_id' => $instance->id,
                'env_key' => AgentationEndpoint::URL_KEY,
            ],
            ['env_value' => AgentationEndpoint::STORED_URL],
        );
    }

    public function forget(Instance $instance): void
    {
        AppInstanceEnvironmentValue::query()
            ->where('instance_id', $instance->id)
            ->where('env_key', AgentationEndpoint::URL_KEY)
            ->delete();
    }
}
