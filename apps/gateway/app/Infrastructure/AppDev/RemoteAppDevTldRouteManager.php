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
                    dns_state_candidate=/etc/wireguard/.orbit.dns-link.candidate
                    trap 'rm -f -- "$candidate" "$dns_state_candidate"' EXIT

                    if [ ! -f "$live" ] || [ -L "$live" ] || [ ! -s "$dns_state" ]; then
                        exit 42
                    fi

                    mapfile -t dns < "$dns_state"
                    dns_link=${dns[0]:-}
                    dns_server=${dns[1]:-}
                    dns_domains=("${dns[@]:2}")
                    if [[ ! "$dns_link" =~ ^[A-Za-z0-9_.:+-]+$ ]] \
                        || [[ ! "$dns_server" =~ ^[A-Fa-f0-9:.]+$ ]] \
                        || [ "${#dns_domains[@]}" -eq 0 ]; then
                        exit 42
                    fi
                    for dns_domain in "${dns_domains[@]}"; do
                        if [[ ! "$dns_domain" =~ ^[A-Za-z0-9.-]+$ ]]; then
                            exit 42
                        fi
                    done

                    if [[ " ${dns_domains[*]} " = *' . '* ]]; then
                        dns_domains=(".")
                    else
                        domain=${dns_domains[0]}
                        dns_domains=("$domain")
                        if [ "$tld" != "$domain" ]; then
                            dns_domains+=("$tld")
                        fi
                    fi

                    resolvectl_domains=()
                    persistent_domains=()
                    for dns_domain in "${dns_domains[@]}"; do
                        resolvectl_domains+=("~$dns_domain")
                        printf -v dns_domain_escaped '%q' "~$dns_domain"
                        persistent_domains+=("$dns_domain_escaped")
                    done

                    php -r '
                        [$live, $candidate, $server, $domains] = array_slice($argv, 1);
                        $configuration = file_get_contents($live);
                        if (!is_string($configuration)) { exit(42); }
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
                    ' -- "$live" "$candidate" "$dns_server" "${persistent_domains[*]}"

                    chown root:root "$candidate"
                    chmod 0600 "$candidate"
                    wg-quick strip "$candidate" >/dev/null
                    printf '%s\n' "$dns_link" "$dns_server" "${dns_domains[@]}" > "$dns_state_candidate"
                    chmod 0600 "$dns_state_candidate"
                    mv -fT -- "$candidate" "$live"
                    resolvectl dns "$dns_link" "$dns_server"
                    resolvectl domain "$dns_link" "${resolvectl_domains[@]}"
                    mv -fT -- "$dns_state_candidate" "$dns_state"
                    BASH,
            ),
            step: 'app-dev-tld-route',
            errorCode: 'app-dev.tld_route_failed',
        );
    }
}
