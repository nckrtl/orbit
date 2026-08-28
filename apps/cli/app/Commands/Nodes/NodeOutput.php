<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

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
}
