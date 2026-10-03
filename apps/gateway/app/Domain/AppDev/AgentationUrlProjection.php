<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;

final readonly class AgentationUrlProjection
{
    public function project(Instance $instance, bool $annotator = false): void
    {
        InstanceEnvironmentValue::query()->updateOrCreate(
            [
                'instance_id' => $instance->id,
                'env_key' => $annotator ? AnnotatorEndpoint::URL_KEY : AgentationEndpoint::URL_KEY,
            ],
            ['env_value' => $annotator ? AnnotatorEndpoint::STORED_URL : AgentationEndpoint::STORED_URL],
        );
    }

    public function forget(Instance $instance, bool $annotator = false): void
    {
        InstanceEnvironmentValue::query()
            ->where('instance_id', $instance->id)
            ->where('env_key', $annotator ? AnnotatorEndpoint::URL_KEY : AgentationEndpoint::URL_KEY)
            ->delete();
    }
}
