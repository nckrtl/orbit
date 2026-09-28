<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;

final readonly class AgentationUrlProjection
{
    public function project(Instance $instance): void
    {
        InstanceEnvironmentValue::query()->updateOrCreate(
            [
                'instance_id' => $instance->id,
                'env_key' => AgentationEndpoint::URL_KEY,
            ],
            ['env_value' => AgentationEndpoint::STORED_URL],
        );
    }

    public function forget(Instance $instance): void
    {
        InstanceEnvironmentValue::query()
            ->where('instance_id', $instance->id)
            ->where('env_key', AgentationEndpoint::URL_KEY)
            ->delete();
    }
}
