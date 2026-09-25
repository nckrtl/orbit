<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;

/**
 * Reads a Node's live `/etc/caddy/Caddyfile` through its symlink. A Node Caddy build keeps every site in
 * that one file, so nothing it imports is read. A missing file reads as empty. It changes nothing on the Node.
 */
final readonly class NodeCaddyLiveReader
{
    public function __construct(
        private NodeCaddyTransport $transport,
        private string $caddyDirectory = '/etc/caddy',
    ) {}

    public function read(Node $node, float $timeout = 60.0): string
    {
        $result = $this->transport->run($node, $this->command($timeout));

        if (! $result->succeeded() || $result->truncated) {
            throw new NodeCaddyBuildException($node->name, 'read-live', trim($result->stderr) ?: 'The live Caddyfile could not be read.');
        }

        return $result->stdout;
    }

    public function command(float $timeout = 60.0): RemoteCommand
    {
        return new RemoteCommand(
            arguments: ['sudo', 'bash', '-seu', '--', $this->caddyDirectory.'/Caddyfile'],
            input: <<<'BASH'
                live=$1
                if [ -e "$live" ]; then
                    cat -- "$(readlink -f -- "$live")"
                fi
                BASH,
            maxOutputBytes: 4_194_304,
            timeout: $timeout,
        );
    }
}
