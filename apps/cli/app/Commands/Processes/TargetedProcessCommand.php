<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Processes\AppInstanceProcessTarget;
use Orbit\Sdk\Requests\Processes\NodeProcessTarget;

abstract class TargetedProcessCommand extends ProcessCommand
{
    protected function processTarget(GatewayConnector $connector): AppInstanceProcessTarget|NodeProcessTarget|null
    {
        $instance = $this->option('instance');
        $node = $this->option('node');
        $hasInstance = is_string($instance) && $instance !== '';
        $hasNode = is_string($node) && $node !== '';

        if ($hasInstance && $hasNode) {
            $this->renderGatewayFailure(
                'process.target_invalid',
                'Use either --instance or --node, not both.',
            );

            return null;
        }

        if (! $hasInstance && ! $hasNode) {
            $this->renderGatewayFailure(
                'process.target_invalid',
                'The --instance or --node option is required.',
            );

            return null;
        }

        if ($hasInstance) {
            $id = filter_var($instance, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if (! is_int($id)) {
                $this->renderGatewayFailure(
                    'process.target_id_invalid',
                    'AppInstance ID must be a positive integer.',
                );

                return null;
            }

            return new AppInstanceProcessTarget($id);
        }

        $nodeId = $this->resolveNodeId($connector, $node);

        if ($nodeId === null) {
            return null;
        }

        return new NodeProcessTarget($nodeId);
    }
}
