<?php

declare(strict_types=1);

namespace App\Infrastructure\ProxyCli;

use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;

/** Installs and removes the Orbit collector script on the Process Node. */
final readonly class ProxyCliSourcePublisher
{
    public function command(string $script): RemoteCommand
    {
        $encoded = base64_encode($script);
        $directory = ProxyCliFootprint::SourceDirectory;
        $path = ProxyCliFootprint::SourcePath;

        $body = <<<BASH
            directory={$directory}
            path={$path}
            install -d -o root -g root -m 0755 -- "\$directory"
            candidate="\$path.orbit-candidate"
            trap 'rm -f -- "\$candidate"' EXIT
            printf '%s' '{$encoded}' | base64 --decode > "\$candidate"
            chmod 0644 "\$candidate"
            if [ -f "\$path" ] && cmp -s -- "\$candidate" "\$path"; then
                rm -f -- "\$candidate"
                exit 0
            fi
            mv -fT -- "\$candidate" "\$path"
            BASH;

        return new RemoteCommand(
            arguments: ['sudo', 'bash', '-seu'],
            protectedInput: ProtectedInput::fromString($body),
        );
    }

    public function removeCommand(): RemoteCommand
    {
        return new RemoteCommand(
            arguments: ['sudo', 'rm', '-rf', '--', ProxyCliFootprint::SourceDirectory],
        );
    }
}
