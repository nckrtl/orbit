<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use Orbit\Sdk\Responses\Nodes\NodeResponse;

final class NodeOutput
{
    public static function sshEndpoint(NodeResponse $node): string
    {
        if ($node->sshUser === '' || $node->publicSshHost === '' || $node->publicSshPort < 1) {
            return '-';
        }

        return "{$node->sshUser}@{$node->publicSshHost}:{$node->publicSshPort}";
    }

    public static function roles(NodeResponse $node): string
    {
        if ($node->roleAssignments === []) {
            return $node->roles === [] ? '-' : implode(', ', $node->roles);
        }

        return implode(', ', array_map(
            static function ($assignment): string {
                $summary = "{$assignment->role}: {$assignment->status}";

                if ($assignment->localActionRequired && $assignment->localCommand !== null) {
                    $summary .= " ({$assignment->localCommand})";
                }

                return $summary;
            },
            $node->roleAssignments,
        ));
    }
}
