<?php

declare(strict_types=1);

namespace App\Infrastructure\Fleet\Footprint;

use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Fleet\NodeShell;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use App\Models\NodeRole;

/**
 * The systemd-tmpfiles rule on an `app-dev` Node that empties Orbit's scratch directories in `/tmp` and
 * `/dev/shm` a day after their contents last changed. Task agents, task checks, and Orbit's test suites run
 * there and name their scratch `orbit-*`; a run that is killed leaves it behind. Orbit's own `/dev/shm/orbit`
 * does not match. The rule touches no other path.
 */
final readonly class TmpfilesFootprintArtifact implements NodeFootprintArtifact
{
    public const string Path = '/etc/tmpfiles.d/orbit.conf';

    public const string Rule = <<<'CONF'
        # Managed by Orbit. Empties Orbit's scratch directories one day after their contents last changed.
        e /tmp/orbit-* - - - 1d
        e /dev/shm/orbit-* - - - 1d

        CONF;

    public function __construct(private NodeShell $shell) {}

    public function name(): string
    {
        return 'tmpfiles';
    }

    public function applies(Node $node): bool
    {
        return $node->platform === 'linux' && $node->roles->contains(
            static fn (NodeRole $role): bool => $role->role === RoleName::AppDev && $role->status === LifecycleStatus::Active,
        );
    }

    public function digest(Node $node): string
    {
        return hash('sha256', self::Rule);
    }

    public function apply(Node $node): bool
    {
        $result = $this->shell->run($node, new RemoteCommand(
            arguments: ['sudo', 'bash', '-seu', '--', self::Path, base64_encode(self::Rule)],
            input: <<<'BASH'
                path=$1
                desired=$(printf '%s' "$2" | base64 --decode; printf x)
                desired=${desired%x}
                if [ -f "$path" ] && [ ! -L "$path" ] && [ "$(stat -c '%U:%G:%a' -- "$path")" = root:root:644 ] \
                    && [ "$(cat -- "$path"; printf x)" = "${desired}x" ]; then
                    exit 0
                fi
                candidate=$(mktemp "$path.XXXXXX")
                trap 'rm -f -- "$candidate"' EXIT
                printf '%s' "$desired" > "$candidate"
                chmod 0644 -- "$candidate"
                mv -f -- "$candidate" "$path"
                printf 'changed\n'
                BASH,
        ));

        if (! $result->succeeded()) {
            throw new ResourceOperationException('node.footprint_tmpfiles_failed', 'The tmpfiles rule could not be published: '.trim($result->stderr), 502);
        }

        return str_contains($result->stdout, 'changed');
    }
}
