<?php

declare(strict_types=1);

namespace App\Infrastructure\ProxyCli;

use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;

/**
 * Installs and removes the Orbit collector script on the Process Node. The install prints
 * `orbit-proxycli-source=unchanged` when the live script already matches, and
 * `orbit-proxycli-source=published` after it replaced it.
 */
final readonly class ProxyCliSourcePublisher
{
    public const string Published = 'orbit-proxycli-source=published';

    public const string Unchanged = 'orbit-proxycli-source=unchanged';

    public function command(string $script): RemoteCommand
    {
        $encoded = base64_encode($script);
        $directory = ProxyCliFootprint::SourceDirectory;
        $path = ProxyCliFootprint::SourcePath;

        $published = self::Published;
        $unchanged = self::Unchanged;
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
                echo {$unchanged}
                exit 0
            fi
            mv -fT -- "\$candidate" "\$path"
            echo {$published}
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
