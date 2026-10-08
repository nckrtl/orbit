<?php

declare(strict_types=1);

namespace App\Commands\Fleet;

use App\Commands\GatewayCommand;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Responses\Fleet\FleetRolloutNodeResponse;
use Orbit\Sdk\Responses\Fleet\FleetRolloutStatusResponse;

/** Renders the fleet rollout of ADR 0202 for `fleet:rollout:status` and `fleet:rollout:resume`. */
abstract class FleetRolloutCommand extends GatewayCommand
{
    protected function renderRollout(FleetRolloutStatusResponse $status, string $title): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($status->toArray());

            return self::SUCCESS;
        }

        $rollout = $status->rollout;
        ConsoleWriter::write($this->output, $this->humanRenderer()->detail($title, array_filter([
            'Rollout' => $status->enabled ? 'on' : 'off (ORBIT_FLEET_ROLLOUT)',
            'Status' => $status->status,
            'Desired commit' => $status->desiredCommit === null ? 'unknown' : substr($status->desiredCommit, 0, 12),
            'Rollout ID' => $rollout?->id,
            'Release' => $rollout?->release,
            'Commit' => $rollout === null ? null : substr($rollout->commit, 0, 12),
            'Halted at' => $rollout?->haltedNode,
            'Error' => $rollout?->errorCode,
            'Message' => $rollout?->message,
        ], static fn (mixed $value): bool => $value !== null)));

        if ($rollout !== null) {
            ConsoleWriter::write($this->output, $this->humanRenderer()->table(
                ['#', 'Node', 'Outcome', 'Step', 'CLI', 'Agent', 'Error'],
                array_map(static fn (FleetRolloutNodeResponse $node): array => [
                    (string) $node->position,
                    $node->node,
                    $node->outcome,
                    $node->step ?? '',
                    $node->cliVersion ?? '',
                    $node->agentVersion ?? '',
                    $node->errorCode ?? '',
                ], $rollout->nodes),
                'The rollout set is empty.',
            ));
        }

        if ($status->excluded !== []) {
            $this->writeHumanMessage('Not in the rollout: '.implode(', ', array_map(
                static fn (array $node): string => "{$node['node']} ({$node['reason']})",
                $status->excluded,
            )).'.');
        }

        $this->writeHumanMessage("Request ID: {$status->requestId}");

        return self::SUCCESS;
    }
}
