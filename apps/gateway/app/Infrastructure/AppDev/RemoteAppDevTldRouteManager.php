<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\AppDevTldRouteManager;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;

final readonly class RemoteAppDevTldRouteManager implements AppDevTldRouteManager
{
    public function __construct(
        private AppDevSshExecutor $ssh,
    ) {}

    public function converge(Node $node): void
    {
        if (! is_string($node->tld) || $node->tld === '') {
            throw new RuntimeConvergenceException(
                step: 'app-dev-tld-route',
                errorCode: 'app-dev.tld_route_failed',
                message: "Node [{$node->name}] has no app development TLD.",
            );
        }

        $this->ssh->execute(
            $node,
            new RemoteCommand(
                arguments: ['sudo', 'bash', '-seu', '--', $node->tld],
                input: <<<'BASH'
                    tld=$1
                    case "$tld" in
                        ''|*[!A-Za-z0-9.-]*|.*|*.) exit 42 ;;
                    esac

                    exec 9>/run/lock/orbit-wireguard-peer.lock
                    flock -w 30 9
                    live=/etc/wireguard/orbit.conf
                    candidate=/etc/wireguard/orbit-candidate.conf
                    dns_state=/etc/wireguard/orbit.dns-link
                    trap 'rm -f -- "$candidate"' EXIT

                    if [ ! -f "$live" ] || [ -L "$live" ] || [ ! -s "$dns_state" ]; then
                        exit 42
                    fi

                    mapfile -t dns < "$dns_state"
                    dns_link=${dns[0]:-}
                    dns_server=${dns[1]:-}
                    domain=${dns[2]:-}
                    if [[ ! "$dns_link" =~ ^[A-Za-z0-9_.:+-]+$ ]] \
                        || [[ ! "$dns_server" =~ ^[A-Fa-f0-9:.]+$ ]] \
                        || [[ ! "$domain" =~ ^[A-Za-z0-9.-]+$ ]]; then
                        exit 42
                    fi

                    php -r '
                        [$live, $candidate, $server, $domain, $tld] = array_slice($argv, 1);
                        $configuration = file_get_contents($live);
                        if (!is_string($configuration)) { exit(42); }
                        $domains = "\\~{$domain}" . ($tld === $domain ? "" : " \\~{$tld}");
                        $replacement = "PostUp = resolvectl dns %i {$server}; resolvectl domain %i {$domains}";
                        $updated = preg_replace(
                            "/^PostUp = resolvectl dns %i [^;\\r\\n]+; resolvectl domain %i [^\\r\\n]+$/m",
                            $replacement,
                            $configuration,
                            -1,
                            $count,
                        );
                        if (!is_string($updated) || $count !== 1 || file_put_contents($candidate, $updated) === false) {
                            exit(42);
                        }
                    ' -- "$live" "$candidate" "$dns_server" "$domain" "$tld"

                    chown root:root "$candidate"
                    chmod 0600 "$candidate"
                    wg-quick strip "$candidate" >/dev/null
                    mv -fT -- "$candidate" "$live"
                    resolvectl dns "$dns_link" "$dns_server"
                    if [ "$tld" = "$domain" ]; then
                        resolvectl domain "$dns_link" "~$domain"
                    else
                        resolvectl domain "$dns_link" "~$domain" "~$tld"
                    fi
                    BASH,
            ),
            step: 'app-dev-tld-route',
            errorCode: 'app-dev.tld_route_failed',
        );
    }
}
