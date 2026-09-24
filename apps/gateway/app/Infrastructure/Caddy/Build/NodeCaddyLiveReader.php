<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;

/**
 * Reads a Node's live Caddy configuration as one text, with the fragments of the old layout inlined
 * where the live file imports them. It changes nothing on the Node.
 */
final readonly class NodeCaddyLiveReader
{
    public function __construct(
        private NodeCaddyTransport $transport,
        private string $caddyDirectory = '/etc/caddy',
    ) {}

    public function read(Node $node): string
    {
        $result = $this->transport->run($node, $this->command());

        if (! $result->succeeded()) {
            throw new NodeCaddyBuildException($node->name, 'read-live', trim($result->stderr) ?: 'The live Caddyfile could not be read.');
        }

        return $result->stdout;
    }

    public function command(): RemoteCommand
    {
        return new RemoteCommand(
            arguments: ['sudo', 'bash', '-seu', '--', $this->caddyDirectory.'/Caddyfile'],
            input: <<<'BASH'
                live=$1
                if [ ! -e "$live" ]; then
                    exit 0
                fi
                main=$(readlink -f -- "$live")
                while IFS= read -r line || [ -n "$line" ]; do
                    trimmed=$(printf '%s' "$line" | sed 's/^[[:space:]]*//')
                    case "$trimmed" in
                        import\ /*)
                            pattern=${trimmed#import }
                            for fragment in $pattern; do
                                if [ -f "$fragment" ]; then
                                    printf '# orbit-live-fragment: %s\n' "$(basename -- "$fragment")"
                                    cat -- "$fragment"
                                    printf '\n'
                                fi
                            done
                            ;;
                        *)
                            printf '%s\n' "$line"
                            ;;
                    esac
                done < "$main"
                BASH,
            maxOutputBytes: 4_194_304,
            timeout: 60.0,
        );
    }
}
