<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;

final readonly class AgentationPortAllocator
{
    public function assign(AppInstance $instance): int
    {
        if (is_int($instance->agentation_port) && $instance->agentation_port >= self::minimum()) {
            return $instance->agentation_port;
        }

        $port = $this->nextAvailable((int) $instance->node_id, $instance->id);
        $instance->forceFill(['agentation_port' => $port])->save();

        return $port;
    }

    public function nextAvailable(int $nodeId, int $ignoreInstanceId): int
    {
        $used = AppInstance::query()
            ->where('node_id', $nodeId)
            ->whereNotNull('agentation_port')
            ->where('id', '!=', $ignoreInstanceId)
            ->pluck('agentation_port')
            ->all();
        $port = AgentationEndpoint::PORT;

        while (in_array($port, $used, true)) {
            if ($port >= 65_535) {
                throw new ResourceOperationException(
                    errorCode: 'process.agentation_ports_exhausted',
                    message: 'No available Agentation port remains on this Node.',
                    status: 409,
                );
            }

            $port++;
        }

        return $port;
    }

    public function release(AppInstance $instance): void
    {
        if ($instance->agentation_port === null) {
            return;
        }

        $instance->forceFill(['agentation_port' => null])->save();
    }

    private static function minimum(): int
    {
        return AgentationEndpoint::PORT;
    }
}
