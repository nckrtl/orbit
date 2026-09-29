<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use App\Commands\Concerns\SelectsProjectDefinitionTarget;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Processes\InstanceProcessTarget;
use Orbit\Sdk\Requests\Processes\NodeProcessTarget;

abstract class TargetedProcessCommand extends ProcessCommand
{
    use SelectsProjectDefinitionTarget;

    /** @return 'app'|'instance'|'node'|null */
    protected function exclusiveProcessTarget(): ?string
    {
        $hasProject = $this->providedProjectOption();
        $hasInstance = $this->providedOption('instance');
        $hasNode = $this->providedOption('node');
        $count = (int) $hasProject + (int) $hasInstance + (int) $hasNode;

        if ($count > 1) {
            $this->renderGatewayFailure(
                'process.target_invalid',
                'Use only one of --project, --instance, or --node.',
            );

            return null;
        }

        if ($count === 0) {
            $this->renderGatewayFailure(
                'process.target_invalid',
                'The --project, --instance, or --node option is required.',
            );

            return null;
        }

        return match (true) {
            $hasProject => 'app',
            $hasInstance => 'instance',
            default => 'node',
        };
    }

    protected function processTarget(GatewayConnector $connector, bool $allowDomain = false): InstanceProcessTarget|NodeProcessTarget|null
    {
        $target = $this->exclusiveProcessTarget();

        if ($target === null || $target === 'app') {
            return null;
        }

        if ($target === 'instance') {
            $id = filter_var($this->option('instance'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            $selector = $this->option('instance');
            if ($allowDomain && ! is_int($id) && is_string($selector) && preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.[a-z0-9-]+\z/D', $selector) === 1) {
                return new InstanceProcessTarget($selector);
            }

            if (! is_int($id)) {
                $this->renderGatewayFailure(
                    'process.target_id_invalid',
                    'Instance ID must be a positive integer.',
                );

                return null;
            }

            return new InstanceProcessTarget($id);
        }

        $nodeId = $this->resolveNodeId($connector, $this->option('node'));

        return $nodeId === null ? null : new NodeProcessTarget($nodeId);
    }
}
