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
}
