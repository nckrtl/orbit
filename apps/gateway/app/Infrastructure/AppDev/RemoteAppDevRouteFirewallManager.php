<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;

final readonly class RemoteAppDevRouteFirewallManager
{
    public function __construct(
        private AppDevSshExecutor $ssh,
    ) {}

    public function remove(Node $node, int $routeId): void
    {
        $this->ssh->execute(
            $node,
            new RemoteCommand(
                arguments: ['bash', '-seu', '--', "orbit:route-{$routeId}-lan"],
                input: <<<'BASH'
                    comment=$1
                    status=$(sudo ufw status numbered)
                    numbers=$(printf '%s\n' "$status" | awk -v suffix="# $comment" '
                        index($0, suffix) == length($0) - length(suffix) + 1 {
                            line=$0
                            sub(/^\[[[:space:]]*/, "", line)
                            sub(/\].*$/, "", line)
                            print line
                        }
                    ')
                    if [ -n "$numbers" ]; then
                        printf '%s\n' "$numbers" | sort -rn | while read -r number; do
                            case "$number" in
                                ''|*[!0-9]*) exit 1 ;;
                            esac
                            sudo ufw --force delete "$number"
                        done
                    fi
                    ! sudo ufw status numbered | grep -F -- "# $comment" >/dev/null
                    BASH,
            ),
            step: 'route-firewall-remove',
            errorCode: 'app-dev.route_firewall_failed',
        );
    }
}
