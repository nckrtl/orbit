<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use LaravelZero\Framework\Commands\Command;
use Orbit\Sdk\Responses\Nodes\NodeAccessNodeResponse;
use Orbit\Sdk\Responses\Nodes\NodeResponse;

final class NodeOutput
{
    /** @param list<NodeAccessNodeResponse> $nodes */
    public static function accessList(array $nodes): string
    {
        if ($nodes === []) {
            return '-';
        }

        return implode(', ', array_map(
            static fn (NodeAccessNodeResponse $node): string => "{$node->name} (#{$node->id})",
            $nodes,
        ));
    }

    public static function sshEndpoint(NodeResponse $node): string
    {
        if ($node->user === '' || $node->publicSshHost === '' || $node->publicSshPort < 1) {
            return '-';
        }

        return "{$node->user}@{$node->publicSshHost}:{$node->publicSshPort}";
    }

    /**
     * @param list<string> $rolesShed
     * @param list<string> $retainedOnNode
     *
     * @mago-expect lint:excessive-parameter-list Every knob mirrors one field of the shared degradation advisory shape.
     */
    public static function degradationAdvisory(
        Command $command,
        string $nodeName,
        ?string $degradation,
        array $rolesShed,
        array $retainedOnNode,
        ?string $followUp,
    ): void {
        if ($degradation === null) {
            return;
        }

        $command->warn(
            "Warning: Node [{$nodeName}] was "
            .self::degradationDescription($degradation)
            .'. '
            .'Orbit removed only the state it owns.',
        );

        if ($rolesShed !== []) {
            $command->line('Roles shed:');

            foreach ($rolesShed as $role) {
                $command->line("  - {$role}");
            }
        }

        if ($retainedOnNode !== []) {
            $command->line('Left on the node:');

            foreach ($retainedOnNode as $item) {
                $command->line("  - {$item}");
            }
        }

        if ($followUp !== null) {
            $command->comment($followUp);
        }
    }

    private static function degradationDescription(string $degradation): string
    {
        return match ($degradation) {
            'firewall_inactive' => 'reachable, but its firewall was not active',
            default => 'unreachable',
        };
    }
}
